<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

/**
 * Outcome of {@see AnonymizedViewsGenerator::generate()}.
 */
class GeneratorResult
{
	/**
	 * @param string $schema schema the views were generated into
	 * @param string|null $mirrorSchema schema holding the FEDERATED mirror, if used
	 * @param int $viewCount number of generated views
	 * @param int $anonymizedColumnCount number of masked columns across all views
	 * @param array<string, list<string>> $anonymizedColumnsByTable [table => masked columns]
	 * @param list<string> $skippedTables entity tables missing on the source server
	 * @param array<string, string> $failedTables [table => error message]
	 * @param list<string> $staleViews views dropped because no entity maps to them anymore
	 * @param list<string> $sqlStatements collected SQL, only in dry run
	 */
	public function __construct(
		public readonly string $schema,
		public readonly ?string $mirrorSchema,
		public readonly int $viewCount,
		public readonly int $anonymizedColumnCount,
		public readonly array $anonymizedColumnsByTable,
		public readonly array $skippedTables,
		public readonly array $failedTables,
		public readonly array $staleViews,
		public readonly array $sqlStatements,
	) {
	}

	public function hasFailures(): bool
	{
		return $this->failedTables !== [];
	}
}
