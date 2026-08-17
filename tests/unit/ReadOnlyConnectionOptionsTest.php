<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\ReadOnlyConnectionOptions;

class ReadOnlyConnectionOptionsTest extends \Codeception\Test\Unit
{
	public function testFromArrayReadsKnownKeysAndIgnoresTheRest(): void
	{
		$options = ReadOnlyConnectionOptions::fromArray([
			'user' => 'ro',
			'password' => 'secret',
			'dbname' => 'app_anon',
			'host' => 'db',
			'port' => '3307',
			'maxExecutionTimeMs' => 60000,
			'contextWindowTokens' => 200000, // unrelated setting living in the same config
		]);

		$this->assertSame('ro', $options->user);
		$this->assertSame('secret', $options->password);
		$this->assertSame('app_anon', $options->schema);
		$this->assertSame('db', $options->host);
		$this->assertSame(3307, $options->port);
		$this->assertSame(60000, $options->maxExecutionTimeMs);
	}

	public function testEmptyValuesFallBackToDefaults(): void
	{
		$options = ReadOnlyConnectionOptions::fromArray(['user' => null, 'dbname' => null, 'host' => null]);

		$this->assertSame('', $options->user);
		$this->assertNull($options->schema);
		$this->assertNull($options->host);
		$this->assertSame(ReadOnlyConnectionOptions::DEFAULT_MAX_ROWS, $options->maxRows);
	}
}
