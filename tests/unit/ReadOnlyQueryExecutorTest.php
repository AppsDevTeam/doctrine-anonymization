<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\Exception\QueryNotAllowedException;
use ADT\DoctrineAnonymization\ReadOnlyConnectionOptions;
use ADT\DoctrineAnonymization\ReadOnlyQueryExecutor;
use ADT\DoctrineAnonymization\SchemaDescriber;

/**
 * Query validation - the second line of defence in front of the database grant.
 * Nothing here opens a connection.
 */
class ReadOnlyQueryExecutorTest extends BaseTest
{
	private function executor(): ReadOnlyQueryExecutor
	{
		return new ReadOnlyQueryExecutor(
			$this->em(),
			new SchemaDescriber($this->em()),
			new ReadOnlyConnectionOptions(user: 'reporting_ro'),
		);
	}

	/**
	 * @dataProvider allowedQueries
	 */
	public function testAllowedQueryPasses(string $sql): void
	{
		$this->executor()->validateQuery($sql);
		$this->assertTrue(true);
	}

	public function allowedQueries(): array
	{
		return [
			'select' => ['SELECT * FROM person'],
			'show' => ['SHOW TABLES'],
			'describe' => ['DESCRIBE person'],
			'explain' => ['EXPLAIN SELECT 1'],
			'cte' => ['WITH x AS (SELECT 1 AS n) SELECT n FROM x'],
			'trailing semicolon' => ['SELECT 1;'],
			// A forbidden word inside a string literal or a comment must not trip it.
			'keyword in string' => ["SELECT id FROM person WHERE note = 'please DELETE this'"],
			'keyword in line comment' => ['SELECT 1 -- DELETE FROM person'],
			'keyword in block comment' => ['SELECT 1 /* DROP TABLE person */'],
			'keyword in hash comment' => ['SELECT 1 # TRUNCATE TABLE person'],
			'leading comment' => ['/* report */ SELECT 1'],
		];
	}

	/**
	 * @dataProvider rejectedQueries
	 */
	public function testRejectedQueryThrows(string $sql): void
	{
		$this->expectException(QueryNotAllowedException::class);
		$this->executor()->validateQuery($sql);
	}

	public function rejectedQueries(): array
	{
		return [
			'empty' => [''],
			'whitespace' => ['   '],
			'insert' => ['INSERT INTO person (id) VALUES (1)'],
			'update' => ['UPDATE person SET name = "x"'],
			'delete' => ['DELETE FROM person'],
			'drop' => ['DROP TABLE person'],
			'truncate' => ['TRUNCATE TABLE person'],
			'call' => ['CALL someProcedure()'],
			'multi statement' => ['SELECT 1; SELECT 2'],
			'select then drop' => ['SELECT * FROM person; DROP TABLE person'],
			'into outfile' => ["SELECT * FROM person INTO OUTFILE '/tmp/x'"],
			// MySQL DOES execute the content of a versioned comment.
			'hidden in versioned comment' => ['SELECT 1 /*!50000 ; DROP TABLE person */'],
			// "--1" without whitespace is a double minus, not a comment.
			'double dash without space' => ['SELECT 1 --1; DROP TABLE person'],
		];
	}

	public function testSchemaNameDefaultsToAnonSuffix(): void
	{
		$this->assertStringEndsWith('_anon', $this->executor()->getSchemaName());
	}

	public function testExplicitSchemaWins(): void
	{
		$executor = new ReadOnlyQueryExecutor(
			$this->em(),
			new SchemaDescriber($this->em()),
			new ReadOnlyConnectionOptions(user: 'ro', schema: 'reporting'),
		);

		$this->assertSame('reporting', $executor->getSchemaName());
	}

	public function testMissingUserIsRefusedInsteadOfFallingBackToTheAppAccount(): void
	{
		$executor = new ReadOnlyQueryExecutor(
			$this->em(),
			new SchemaDescriber($this->em()),
			new ReadOnlyConnectionOptions(),
		);

		$this->expectException(QueryNotAllowedException::class);
		$executor->execute('SELECT 1');
	}
}
