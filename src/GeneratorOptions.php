<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

/**
 * Input for {@see AnonymizedViewsGenerator::generate()}.
 */
class GeneratorOptions
{
	/**
	 * @param string|null $schema target schema for the views; defaults to "<source db>_anon"
	 * @param string $salt salt for deterministic hashing - keep it secret and stable
	 * @param bool $mask replace personal values with a fixed mask instead of realistic
	 *                   values; hides everything, but JOIN/GROUP BY over masked columns
	 *                   stops making sense
	 * @param bool $dryRun collect the SQL instead of executing it
	 * @param bool $federated mirror the source database on the target server through
	 *                        FEDERATED tables and build the views on top of them; lets
	 *                        you keep the views on a different server than the data
	 *                        (requires --host and the FEDERATED engine)
	 * @param string|null $host target server; null = the connection from the entity manager
	 * @param string|null $grantUser read-only account to create and grant SELECT on the
	 *                               views schema; never granted anything on the source data
	 * @param string|null $grantPassword password enforced for $grantUser; null keeps the existing one
	 * @param int $maxUserConnections resource limit for $grantUser
	 * @param int $maxQueriesPerHour resource limit for $grantUser
	 */
	public function __construct(
		public readonly ?string $schema = null,
		public readonly string $salt = 'anonymization-v1',
		public readonly bool $mask = false,
		public readonly bool $dryRun = false,
		public readonly bool $federated = false,
		public readonly ?string $host = null,
		public readonly int $port = 3306,
		public readonly ?string $dbUser = null,
		public readonly ?string $dbPassword = null,
		public readonly ?string $dbName = null,
		public readonly ?string $grantUser = null,
		public readonly ?string $grantPassword = null,
		public readonly string $grantHost = '%',
		public readonly int $maxUserConnections = 5,
		public readonly int $maxQueriesPerHour = 2000,
	) {
	}
}
