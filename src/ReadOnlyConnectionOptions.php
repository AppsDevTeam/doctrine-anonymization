<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

/**
 * Connection and limits for {@see ReadOnlyQueryExecutor}.
 */
class ReadOnlyConnectionOptions
{
	public const DEFAULT_MAX_ROWS = 1000;
	public const DEFAULT_MAX_EXPORT_ROWS = 100000;
	public const DEFAULT_MAX_EXECUTION_TIME_MS = 5000;
	public const DEFAULT_EXPORT_MAX_EXECUTION_TIME_MS = 60000;

	/**
	 * @param string $user dedicated read-only account with SELECT on the anonymized
	 *                     schema ONLY - never the application account, which can read
	 *                     the real data
	 * @param string|null $schema anonymized schema; defaults to "<app database>_anon"
	 * @param string|null $host defaults to the host of the application connection
	 * @param int|null $port defaults to the port of the application connection
	 * @param int $maxRows hard cap on rows materialised by {@see ReadOnlyQueryExecutor::execute()}
	 * @param int $maxExportRows hard cap for {@see ReadOnlyQueryExecutor::streamQuery()}
	 */
	public function __construct(
		public readonly string $user = '',
		public readonly string $password = '',
		public readonly ?string $schema = null,
		public readonly ?string $host = null,
		public readonly ?int $port = null,
		public readonly int $maxRows = self::DEFAULT_MAX_ROWS,
		public readonly int $maxExportRows = self::DEFAULT_MAX_EXPORT_ROWS,
		public readonly int $maxExecutionTimeMs = self::DEFAULT_MAX_EXECUTION_TIME_MS,
		public readonly int $exportMaxExecutionTimeMs = self::DEFAULT_EXPORT_MAX_EXECUTION_TIME_MS,
	) {
	}

	/**
	 * Builds the options from a plain config array, which is handy when the values
	 * come from a framework config file.
	 *
	 * Recognised keys: user, password, dbname (or schema), host, port, maxRows,
	 * maxExportRows, maxExecutionTimeMs, exportMaxExecutionTimeMs. Unknown keys are
	 * ignored, so the array may carry unrelated settings as well.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function fromArray(array $config): self
	{
		$default = new self();

		return new self(
			user: (string) ($config['user'] ?? ''),
			password: (string) ($config['password'] ?? ''),
			schema: ($config['schema'] ?? $config['dbname'] ?? null) ? (string) ($config['schema'] ?? $config['dbname']) : null,
			host: ($config['host'] ?? null) ? (string) $config['host'] : null,
			port: ($config['port'] ?? null) ? (int) $config['port'] : null,
			maxRows: (int) ($config['maxRows'] ?? $default->maxRows),
			maxExportRows: (int) ($config['maxExportRows'] ?? $default->maxExportRows),
			maxExecutionTimeMs: (int) ($config['maxExecutionTimeMs'] ?? $default->maxExecutionTimeMs),
			exportMaxExecutionTimeMs: (int) ($config['exportMaxExecutionTimeMs'] ?? $default->exportMaxExecutionTimeMs),
		);
	}
}
