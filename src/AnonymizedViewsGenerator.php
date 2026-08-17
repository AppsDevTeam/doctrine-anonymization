<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use ADT\DoctrineAnonymization\Masking\DefaultMaskingStrategy;
use ADT\DoctrineAnonymization\Masking\MaskingStrategy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Throwable;

/**
 * Generates database views exposing the data with personal columns masked.
 *
 * For every entity table a view is created in a separate schema (by default
 * `<dbname>_anon`):
 *
 *  - columns without {@see Attributes\Anonymize}, and those the policy keeps
 *    readable, pass through 1:1,
 *  - masked columns are replaced by an SQL expression - a fixed mask, or a
 *    deterministic hash so that JOIN and GROUP BY still work while the original
 *    value is unreadable; NULL stays NULL,
 *  - the shape of specific types can be preserved through a {@see MaskingStrategy}.
 *
 * Views are created with `SQL SECURITY DEFINER` on purpose: a read-only account then
 * needs no privileges on the source tables, which hold the readable personal data.
 * Grant it SELECT on the views schema only ({@see GeneratorOptions::$grantUser}).
 *
 * The column list of each view is fixed at generation time, so a newly added source
 * column simply is not in the view until the views are regenerated - fail-closed.
 * Regenerate as part of your deploy, right after migrations.
 */
class AnonymizedViewsGenerator
{
	public const FEDERATED_SERVER_NAME = 'anonymization_source';
	public const MASK_VALUE = '*****';

	private MaskingStrategy $maskingStrategy;

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly AnonymizationPolicy $policy,
		?MaskingStrategy $maskingStrategy = null,
	) {
		$this->maskingStrategy = $maskingStrategy ?? new DefaultMaskingStrategy();
	}

	/**
	 * @throws \RuntimeException on invalid options
	 */
	public function generate(GeneratorOptions $options): GeneratorResult
	{
		if ($options->federated && $options->host === null) {
			throw new \RuntimeException('The federated mode requires a host (target server for the mirror and views).');
		}

		// Target = server where the views (and the mirror) are created.
		// Source = server holding the data.
		$target = $this->createTargetConnection($options);
		$source = $options->federated ? $this->em->getConnection() : $target;

		$sourceDb = $options->federated
			? $source->getDatabase()
			: ($options->dbName ?? $source->getDatabase());

		if ($sourceDb === null) {
			throw new \RuntimeException('Cannot detect the source database name.');
		}

		$schema = $options->schema ?? $sourceDb . '_anon';

		if (!$options->federated && $schema === $sourceDb) {
			throw new \RuntimeException('The target schema must differ from the source database.');
		}

		$sqlStatements = [];
		$execute = function (string $sql) use ($target, $options, &$sqlStatements): void {
			if ($options->dryRun) {
				$sqlStatements[] = $sql;
			} else {
				$target->executeStatement($sql);
			}
		};

		$charsetSql = $this->resolveCharset($source, $sourceDb);
		$execute(sprintf('CREATE DATABASE IF NOT EXISTS %s %s', $this->quoteIdentifier($schema), $charsetSql));

		$mirrorSchema = $options->federated ? $schema . '_mirror' : null;
		if ($mirrorSchema !== null) {
			$this->createFederatedServer($execute, $target, $source, $mirrorSchema, $sourceDb, $charsetSql);
		}

		$fromSchema = $mirrorSchema ?? $sourceDb;
		$quotedSalt = $source->quote($options->salt);
		$quotedMask = $source->quote(self::MASK_VALUE);

		$tables = $this->buildTableMap();
		$viewCount = 0;
		$anonymizedColumnCount = 0;
		$anonymizedColumnsByTable = [];
		$skippedTables = [];
		$failedTables = [];

		foreach ($tables as $table => $anonColumns) {
			// The table does not have to exist on the source server (e.g. a local
			// database without all migrations applied).
			$exists = (int) $source->fetchOne(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
				[$sourceDb, $table],
			);
			if ($exists === 0) {
				$skippedTables[] = $table;

				continue;
			}

			$columns = $source->fetchAllAssociative(sprintf(
				'SHOW FULL COLUMNS FROM %s.%s',
				$this->quoteIdentifier($sourceDb),
				$this->quoteIdentifier($table),
			));

			try {
				if ($mirrorSchema !== null) {
					$this->createFederatedTable($execute, $mirrorSchema, $table, $columns, $charsetSql);
				}

				$selects = [];
				foreach ($columns as $column) {
					$name = (string) $column['Field'];
					$quotedName = $this->quoteIdentifier($name);
					$type = $anonColumns[strtolower($name)] ?? null;

					if ($type === null) {
						$selects[] = '`t`.' . $quotedName;

						continue;
					}

					$selects[] = $this->maskingExpression(
						'`t`.' . $quotedName,
						$type,
						(string) $column['Type'],
						$quotedSalt,
						$quotedMask,
						$options->mask,
					) . ' AS ' . $quotedName;

					$anonymizedColumnCount++;
					$anonymizedColumnsByTable[$table][] = $name;
				}

				$execute(sprintf(
					"CREATE OR REPLACE SQL SECURITY DEFINER VIEW %s.%s AS SELECT\n\t%s\nFROM %s.%s `t`",
					$this->quoteIdentifier($schema),
					$this->quoteIdentifier($table),
					implode(",\n\t", $selects),
					$this->quoteIdentifier($fromSchema),
					$this->quoteIdentifier($table),
				));
			} catch (Throwable $e) {
				$failedTables[$table] = $e->getMessage();

				continue;
			}

			$viewCount++;
		}

		$staleViews = $this->dropStaleViews($execute, $target, $schema, $mirrorSchema, array_keys($tables));

		if ($options->grantUser !== null) {
			$this->grantReadOnlyAccess($execute, $target, $schema, $options);
		}

		return new GeneratorResult(
			$schema,
			$mirrorSchema,
			$viewCount,
			$anonymizedColumnCount,
			$anonymizedColumnsByTable,
			$skippedTables,
			$failedTables,
			$staleViews,
			$sqlStatements,
		);
	}

	/**
	 * Tables and their masked columns according to the attributes and the policy.
	 * Entities using single table inheritance share a table, so their columns merge.
	 *
	 * @return array<string, array<string, AnonymizationTypeInterface>> [table => [lowercase column => type]]
	 */
	public function buildTableMap(): array
	{
		$tables = [];

		/** @var ClassMetadata $metadata */
		foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
			if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
				continue;
			}

			$table = $metadata->getTableName();
			$tables[$table] ??= [];
			$class = $metadata->getReflectionClass()->getName();

			foreach ($metadata->getFieldNames() as $fieldName) {
				// Embedded fields ("address.street") are not supported yet.
				if (str_contains($fieldName, '.')) {
					continue;
				}

				$type = MetadataHelper::readAnonymizeType($class, $fieldName);
				if ($type === null || !$this->policy->shouldAnonymize($class, $type)) {
					continue;
				}

				$tables[$table][strtolower($metadata->getColumnName($fieldName))] = $type;
			}

			// Foreign keys can hold personal data too (e.g. a reference to a person).
			foreach ($metadata->getAssociationNames() as $fieldName) {
				if (
					$metadata->isAssociationInverseSide($fieldName)
					|| !$metadata->isSingleValuedAssociation($fieldName)
					|| !$metadata->isAssociationWithSingleJoinColumn($fieldName)
				) {
					continue;
				}

				$type = MetadataHelper::readAnonymizeType($class, $fieldName);
				if ($type === null || !$this->policy->shouldAnonymize($class, $type)) {
					continue;
				}

				$tables[$table][strtolower($metadata->getSingleAssociationJoinColumnName($fieldName))] = $type;
			}
		}

		ksort($tables);

		return $tables;
	}

	private function maskingExpression(
		string $quotedColumn,
		AnonymizationTypeInterface $type,
		string $sqlType,
		string $quotedSalt,
		string $quotedMask,
		bool $mask,
	): string {
		$expression = $this->maskingStrategy->expression($quotedColumn, $type, $sqlType, $quotedSalt, $mask);
		if ($expression !== null) {
			return $expression;
		}

		if ($mask) {
			return sprintf('IF(%s IS NULL, NULL, %s)', $quotedColumn, $quotedMask);
		}

		return sprintf(
			'IF(%s IS NULL, NULL, %s)',
			$quotedColumn,
			$this->genericHashExpression($quotedColumn, $type, $sqlType, $quotedSalt),
		);
	}

	/**
	 * Fallback for types the strategy does not handle: a deterministic hash shaped by
	 * the actual SQL column type, so the value still fits the column.
	 */
	private function genericHashExpression(
		string $quotedColumn,
		AnonymizationTypeInterface $type,
		string $sqlType,
		string $quotedSalt,
	): string {
		$hash = sprintf(
			"SHA2(CONCAT_WS('|', %s, '%s', CAST(%s AS CHAR)), 256)",
			$quotedSalt,
			$type->key(),
			$quotedColumn,
		);
		$hashInt = sprintf('CAST(CONV(SUBSTRING(%s, 1, 8), 16, 10) AS UNSIGNED)', $hash);

		$baseType = strtolower((string) preg_replace('/\W.*/', '', $sqlType));
		$isBool = str_starts_with($sqlType, 'tinyint(1)');

		return match (true) {
			$isBool => sprintf('%s MOD 2', $hashInt),
			in_array($baseType, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) => sprintf('%s MOD 1000000', $hashInt),
			in_array($baseType, ['decimal', 'float', 'double'], true) => sprintf('ROUND((%s MOD 1000000) / 100, 2)', $hashInt),
			$baseType === 'date' => sprintf('DATE(DATE_SUB(%s, INTERVAL (%s MOD 365) DAY))', $quotedColumn, $hashInt),
			in_array($baseType, ['datetime', 'timestamp'], true) => sprintf('DATE_SUB(%s, INTERVAL (%s MOD 365) DAY)', $quotedColumn, $hashInt),
			$baseType === 'time' => sprintf('SEC_TO_TIME(%s MOD 86400)', $hashInt),
			$baseType === 'year' => sprintf('1950 + (%s MOD 60)', $hashInt),
			default => sprintf('SUBSTRING(%s, 1, 24)', $hash),
		};
	}

	private function createTargetConnection(GeneratorOptions $options): Connection
	{
		if ($options->host === null) {
			return $this->em->getConnection();
		}

		$params = $this->em->getConnection()->getParams();

		$connectionParams = [
			'driver' => is_string($params['driver'] ?? null) ? $params['driver'] : 'pdo_mysql',
			'host' => $options->host,
			'port' => $options->port,
			'user' => (string) ($options->dbUser ?? $params['user'] ?? ''),
			'password' => (string) ($options->dbPassword ?? $params['password'] ?? ''),
		];

		// In federated mode everything uses fully qualified names and the source
		// database does not have to exist on the target server.
		if (!$options->federated) {
			$connectionParams['dbname'] = (string) ($options->dbName ?? $params['dbname'] ?? '');
		}

		return DriverManager::getConnection($connectionParams);
	}

	private function resolveCharset(Connection $source, string $sourceDb): string
	{
		$charset = $source->fetchAssociative(
			'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation
			FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
			[$sourceDb],
		) ?: [];

		return sprintf(
			'CHARACTER SET %s COLLATE %s',
			$charset['charset'] ?? 'utf8mb4',
			$charset['collation'] ?? 'utf8mb4_unicode_ci',
		);
	}

	/**
	 * @param callable(string): void $execute
	 */
	private function createFederatedServer(
		callable $execute,
		Connection $target,
		Connection $source,
		string $mirrorSchema,
		string $sourceDb,
		string $charsetSql,
	): void {
		$execute(sprintf('CREATE DATABASE IF NOT EXISTS %s %s', $this->quoteIdentifier($mirrorSchema), $charsetSql));

		$sourceParams = $source->getParams();
		$execute('DROP SERVER IF EXISTS ' . self::FEDERATED_SERVER_NAME);
		$execute(sprintf(
			'CREATE SERVER %s FOREIGN DATA WRAPPER mysql OPTIONS (HOST %s, PORT %d, USER %s, PASSWORD %s, DATABASE %s)',
			self::FEDERATED_SERVER_NAME,
			$target->quote((string) ($sourceParams['host'] ?? 'localhost')),
			(int) ($sourceParams['port'] ?? 3306),
			$target->quote((string) ($sourceParams['user'] ?? '')),
			$target->quote((string) ($sourceParams['password'] ?? '')),
			$target->quote($sourceDb),
		));
	}

	/**
	 * Mirrors a table through the FEDERATED engine. No data is copied; every SELECT
	 * goes live to the source server.
	 *
	 * @param callable(string): void $execute
	 * @param list<array<string, mixed>> $columns
	 */
	private function createFederatedTable(
		callable $execute,
		string $mirrorSchema,
		string $table,
		array $columns,
		string $charsetSql,
	): void {
		$definitions = [];
		$primaryKey = [];

		foreach ($columns as $column) {
			$definitions[] = sprintf(
				'%s %s %s',
				$this->quoteIdentifier((string) $column['Field']),
				(string) $column['Type'],
				($column['Null'] ?? 'YES') === 'NO' ? 'NOT NULL' : 'NULL',
			);

			if (($column['Key'] ?? null) === 'PRI') {
				$primaryKey[] = $this->quoteIdentifier((string) $column['Field']);
			}
		}

		if ($primaryKey) {
			$definitions[] = 'PRIMARY KEY (' . implode(', ', $primaryKey) . ')';
		}

		$execute(sprintf('DROP TABLE IF EXISTS %s.%s', $this->quoteIdentifier($mirrorSchema), $this->quoteIdentifier($table)));
		$execute(sprintf(
			"CREATE TABLE %s.%s (\n\t%s\n) ENGINE=FEDERATED %s CONNECTION='%s/%s'",
			$this->quoteIdentifier($mirrorSchema),
			$this->quoteIdentifier($table),
			implode(",\n\t", $definitions),
			str_replace('CHARACTER SET', 'DEFAULT CHARACTER SET', $charsetSql),
			self::FEDERATED_SERVER_NAME,
			$table,
		));
	}

	/**
	 * @param callable(string): void $execute
	 * @param list<string> $knownTables
	 * @return list<string>
	 */
	private function dropStaleViews(
		callable $execute,
		Connection $target,
		string $schema,
		?string $mirrorSchema,
		array $knownTables,
	): array {
		$staleViews = array_values(array_diff(
			$target->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?', [$schema]),
			$knownTables,
		));

		foreach ($staleViews as $staleView) {
			$execute(sprintf('DROP VIEW IF EXISTS %s.%s', $this->quoteIdentifier($schema), $this->quoteIdentifier((string) $staleView)));
		}

		if ($mirrorSchema !== null) {
			$staleMirrors = array_diff(
				$target->fetchFirstColumn(
					"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND ENGINE = 'FEDERATED'",
					[$mirrorSchema],
				),
				$knownTables,
			);
			foreach ($staleMirrors as $staleMirror) {
				$execute(sprintf('DROP TABLE IF EXISTS %s.%s', $this->quoteIdentifier($mirrorSchema), $this->quoteIdentifier((string) $staleMirror)));
			}
		}

		return $staleViews;
	}

	/**
	 * Creates the read-only account and grants it SELECT on the views schema - and
	 * only there. The mirror and the source database contain readable personal data,
	 * so they are never granted.
	 *
	 * @param callable(string): void $execute
	 */
	private function grantReadOnlyAccess(callable $execute, Connection $target, string $schema, GeneratorOptions $options): void
	{
		$account = sprintf('%s@%s', $target->quote((string) $options->grantUser), $target->quote($options->grantHost));

		if ($options->grantPassword !== null) {
			$quotedPassword = $target->quote($options->grantPassword);
			$execute(sprintf('CREATE USER IF NOT EXISTS %s IDENTIFIED BY %s', $account, $quotedPassword));
			// CREATE USER IF NOT EXISTS does not touch the password of an existing
			// user, so an explicitly given password is enforced separately.
			$execute(sprintf('ALTER USER %s IDENTIFIED BY %s', $account, $quotedPassword));
		}

		$execute(sprintf('GRANT SELECT ON %s.* TO %s', $this->quoteIdentifier($schema), $account));

		// Resource limits: a runaway reporting query must not exhaust the database.
		$execute(sprintf(
			'ALTER USER IF EXISTS %s WITH MAX_USER_CONNECTIONS %d MAX_QUERIES_PER_HOUR %d',
			$account,
			$options->maxUserConnections,
			$options->maxQueriesPerHour,
		));
	}

	private function quoteIdentifier(string $identifier): string
	{
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
