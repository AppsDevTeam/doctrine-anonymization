<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use ADT\DoctrineAnonymization\Exception\QueryFailedException;
use ADT\DoctrineAnonymization\Exception\QueryNotAllowedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs read-only queries against the anonymized schema.
 *
 * Queries never touch the source database: the executor opens its own connection
 * with the dedicated read-only account ({@see ReadOnlyConnectionOptions::$user}),
 * which has SELECT on the anonymized schema only. The application account is never
 * reused - it can read the real data.
 *
 * On top of that every query is validated (read-only statements only, no
 * multi-statement, no qualified reference to the source database) and bounded by a
 * row cap and `max_execution_time`. **The validation is a second line of defence,
 * not a replacement for the database grant** - keep the grant narrow.
 *
 * Handy whenever an untrusted or semi-trusted consumer needs to query your data:
 * ad-hoc reporting, a BI tool, or an LLM writing its own SQL.
 */
class ReadOnlyQueryExecutor
{
	/**
	 * Keywords that must not appear anywhere in the query. Checked against a version
	 * without string literals and comments, so a keyword hidden in a text value or a
	 * comment does not trip it.
	 */
	private const FORBIDDEN_KEYWORDS = [
		'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE',
		'GRANT', 'REVOKE', 'REPLACE', 'RENAME', 'LOCK', 'UNLOCK',
		'CALL', 'EXEC', 'EXECUTE', 'LOAD', 'HANDLER',
		'INTO\s+OUTFILE', 'INTO\s+DUMPFILE', 'LOAD_FILE',
	];

	private ?Connection $connection = null;

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly SchemaDescriber $schemaDescriber,
		private readonly ReadOnlyConnectionOptions $options,
	) {
	}

	/**
	 * @throws QueryNotAllowedException
	 */
	public function validateQuery(string $sql): void
	{
		$normalized = trim($sql);

		if ($normalized === '') {
			throw new QueryNotAllowedException('Empty SQL query.');
		}

		$stripped = $this->stripStringsAndComments($normalized);

		// A single trailing semicolon is harmless and commonly written; anything else
		// (a semicolon in the middle) is a multi-statement query.
		if (substr_count(rtrim($stripped, "; \t\n\r"), ';') > 0) {
			throw new QueryNotAllowedException('Multi-statement queries are not allowed.');
		}

		if (!preg_match('/^\s*(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', $stripped)) {
			throw new QueryNotAllowedException('Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed.');
		}

		foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
			if (preg_match('/\b' . $keyword . '\b/i', $stripped)) {
				throw new QueryNotAllowedException('Forbidden keyword detected: ' . explode('\\', $keyword)[0]);
			}
		}

		// Block escaping into the source database through a qualified `dbname`.`table`.
		$sourceDb = (string) $this->em->getConnection()->getDatabase();
		if ($sourceDb !== '' && preg_match('/\b' . preg_quote($sourceDb, '/') . '\b\s*`?\s*\./i', str_replace('`', '', $stripped))) {
			throw new QueryNotAllowedException('Queries may only target the anonymized schema.');
		}
	}

	/**
	 * Runs the query and returns at most {@see ReadOnlyConnectionOptions::$maxRows} rows.
	 *
	 * @return list<array<string, mixed>>
	 * @throws QueryNotAllowedException|QueryFailedException
	 */
	public function execute(string $sql): array
	{
		$this->validateQuery($sql);

		// Strip the trailing semicolon the validation allows, so an appended LIMIT
		// does not end up behind it.
		$sql = rtrim(trim($sql), "; \t\n\r");

		if ($this->shouldAppendLimit($sql)) {
			// On a new line, so it cannot end up inside a trailing `--` / `#` comment.
			$sql .= "\nLIMIT " . $this->options->maxRows;
		}

		$connection = $this->getConnection();
		$this->applyExecutionTimeout($connection, $this->options->maxExecutionTimeMs);

		try {
			// Hard row cap independent of the LIMIT in the query - a LIMIT may sit in a
			// subquery only, so it is not relied upon.
			$result = $connection->executeQuery($sql);
			$rows = [];
			while (($row = $result->fetchAssociative()) !== false) {
				$rows[] = $row;
				if (count($rows) >= $this->options->maxRows) {
					break;
				}
			}
			$result->free();

			return $rows;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new QueryFailedException('SQL error: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Streams the result row by row, without materialising it in memory.
	 *
	 * Meant for exports: no interactive row limit, only the (much higher)
	 * {@see ReadOnlyConnectionOptions::$maxExportRows} and a longer execution timeout.
	 * The same validation and read-only connection as {@see execute()}.
	 *
	 * @param callable(array<string, mixed>, int): void $onRow (row, index)
	 * @return int number of rows emitted
	 * @throws QueryNotAllowedException|QueryFailedException
	 */
	public function streamQuery(string $sql, callable $onRow): int
	{
		$this->validateQuery($sql);
		$sql = rtrim(trim($sql), "; \t\n\r");

		$connection = $this->getConnection();
		$this->applyExecutionTimeout($connection, $this->options->exportMaxExecutionTimeMs);

		try {
			$result = $connection->executeQuery($sql);
			$count = 0;
			while (($row = $result->fetchAssociative()) !== false) {
				$onRow($row, $count);
				if (++$count >= $this->options->maxExportRows) {
					break;
				}
			}
			$result->free();

			return $count;
		} catch (\Doctrine\DBAL\Exception $e) {
			throw new QueryFailedException('SQL error: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Names of the tables (views) available in the anonymized schema.
	 *
	 * @return list<string>
	 */
	public function getTables(): array
	{
		return $this->getConnection()->fetchFirstColumn(
			'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
			[$this->getSchemaName()],
		);
	}

	/**
	 * Columns of one table, each with its type, nullability and - where available -
	 * a human readable DESCRIPTION.
	 *
	 * The descriptions come from the entity attributes ({@see SchemaDescriber}),
	 * because generated views do not carry over MySQL column comments.
	 *
	 * @return list<array<string, mixed>>
	 * @throws QueryFailedException when the table does not exist in the schema
	 */
	public function getTableColumns(string $table): array
	{
		$columns = $this->getConnection()->fetchAllAssociative(
			'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_COMMENT
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
			ORDER BY ORDINAL_POSITION',
			[$this->getSchemaName(), $table],
		);

		if (!$columns) {
			throw new QueryFailedException(sprintf('Table "%s" not found.', $table));
		}

		$descriptions = array_change_key_case(
			$this->schemaDescriber->getColumnDescriptions()[strtolower($table)] ?? [],
			CASE_LOWER,
		);

		foreach ($columns as &$column) {
			$description = $descriptions[strtolower((string) $column['COLUMN_NAME'])] ?? null;
			if ($description !== null) {
				$column['DESCRIPTION'] = $description;
			}
		}

		return $columns;
	}

	public function getSchemaName(): string
	{
		return $this->options->schema ?? $this->em->getConnection()->getDatabase() . '_anon';
	}

	/**
	 * Caps the run time of a query. A failure is only logged through the DBAL layer -
	 * `max_execution_time` may not be supported everywhere - and the row cap plus the
	 * resource limits of the read-only account remain as the hard bound.
	 */
	private function applyExecutionTimeout(Connection $connection, int $ms): void
	{
		try {
			$connection->executeStatement('SET SESSION max_execution_time = ' . $ms);
		} catch (\Throwable) {
			// Not supported by this server - the row cap still bounds the work.
		}
	}

	/**
	 * A LIMIT is appended only to SELECT/WITH queries (SHOW/DESCRIBE/EXPLAIN reject
	 * it) and only when the query has none at the top level. Detection runs on a
	 * version without string literals, comments and backtick identifiers, so a
	 * "LIMIT" in a text, in a comment, in a subquery only, or as a column name does
	 * not mislead it.
	 */
	private function shouldAppendLimit(string $sql): bool
	{
		$stripped = $this->stripStringsAndComments($sql);
		$stripped = (string) preg_replace('/`(?:[^`]|``)*`/', '', $stripped);

		if (!preg_match('/^\s*(SELECT|WITH)\b/i', $stripped)) {
			return false;
		}

		return !$this->hasTopLevelLimit($stripped);
	}

	/**
	 * Is there a LIMIT clause outside of any parentheses, i.e. bounding the outer
	 * query rather than a subquery? The input must be free of string literals and
	 * comments.
	 */
	private function hasTopLevelLimit(string $stripped): bool
	{
		$depth = 0;
		$length = strlen($stripped);

		for ($i = 0; $i < $length; $i++) {
			$char = $stripped[$i];

			if ($char === '(') {
				$depth++;
			} elseif ($char === ')') {
				$depth = max(0, $depth - 1);
			} elseif (
				$depth === 0
				&& ($i === 0 || !$this->isWordChar($stripped[$i - 1]))
				&& preg_match('/LIMIT\b/iA', $stripped, $matches, 0, $i)
			) {
				return true;
			}
		}

		return false;
	}

	private function isWordChar(string $char): bool
	{
		return $char === '_' || ctype_alnum($char);
	}

	/**
	 * Removes string literals and comments - the basis for checks that must not fall
	 * for a keyword hidden inside a value or a comment.
	 */
	private function stripStringsAndComments(string $sql): string
	{
		$withoutStrings = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '', $sql);
		$withoutStrings = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '', (string) $withoutStrings);

		return $this->stripComments((string) $withoutStrings);
	}

	/**
	 * Strips SQL comments so they cannot be used to hide a keyword or split tokens.
	 * Follows MySQL semantics:
	 *  - the content of versioned comments `/*!50000 ...` IS executed by MySQL, so it
	 *    is kept (only the delimiters go) to be seen by the keyword check,
	 *  - `--` starts a comment only when followed by whitespace or end of line
	 *    (otherwise it is a double minus and the rest is executed),
	 *  - `#` always comments out the rest of the line.
	 * Call only after string literals have been removed.
	 */
	private function stripComments(string $sql): string
	{
		$sql = (string) preg_replace('~/\*!\d*(.*?)\*/~s', ' $1 ', $sql);
		$sql = (string) preg_replace('~/\*.*?\*/~s', ' ', $sql);
		$sql = (string) preg_replace('~/\*.*$~s', ' ', $sql); // unterminated block comment
		$sql = (string) preg_replace('~--(?=\s|$).*$~m', ' ', $sql);
		$sql = (string) preg_replace('~#.*$~m', ' ', $sql);

		return $sql;
	}

	private function getConnection(): Connection
	{
		if ($this->connection !== null) {
			return $this->connection;
		}

		// Pointing at the anonymized schema is NOT what keeps the consumer inside it -
		// a qualified name could still reach elsewhere. The dedicated read-only account
		// with SELECT on that schema only is what does. Hence the application account
		// is never reused: it can read the real data.
		if ($this->options->user === '') {
			throw new QueryNotAllowedException(
				'No read-only database user is configured. It must be a dedicated account with '
				. 'GRANT SELECT only on the anonymized schema.',
			);
		}

		$params = $this->em->getConnection()->getParams();

		return $this->connection = DriverManager::getConnection([
			'driver' => is_string($params['driver'] ?? null) ? $params['driver'] : 'pdo_mysql',
			'host' => (string) ($this->options->host ?? $params['host'] ?? 'localhost'),
			'port' => (int) ($this->options->port ?? $params['port'] ?? 3306),
			'dbname' => $this->getSchemaName(),
			'user' => $this->options->user,
			'password' => $this->options->password,
			'charset' => 'utf8mb4',
		]);
	}
}
