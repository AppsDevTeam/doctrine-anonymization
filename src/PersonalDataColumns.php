<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Registry of column NAMES that always carry a masked direct personal identifier.
 *
 * Meant for consumers that get a query result and no longer know which table a
 * column came from, yet need to redact identifiers from free-form output (a report,
 * an export, an LLM answer).
 *
 * A column name is only listed when it is a direct identifier, is really masked
 * according to {@see AnonymizationPolicy}, AND the same column name is not readable
 * anywhere else. The last condition matters: `NAME` may be a masked client name in
 * one table and a perfectly readable branch name in another - redacting by name
 * alone would then strip legitimate values from reports.
 */
class PersonalDataColumns
{
	/** @var array<string, true>|null */
	private ?array $columns = null;

	public function __construct(
		private readonly EntityManagerInterface $em,
		private readonly AnonymizationPolicy $policy,
	) {
	}

	/**
	 * @return array<string, true> [lowercase column name => true]
	 */
	public function getDirectIdentifierColumns(): array
	{
		if ($this->columns !== null) {
			return $this->columns;
		}

		$identifiers = [];
		$readable = [];

		/** @var ClassMetadata $metadata */
		foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
			if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
				continue;
			}

			$class = $metadata->getReflectionClass()->getName();

			foreach ($metadata->getFieldNames() as $fieldName) {
				if (str_contains($fieldName, '.')) {
					continue;
				}

				$columnName = strtolower($metadata->getColumnName($fieldName));
				$type = MetadataHelper::readAnonymizeType($class, $fieldName);

				if ($type === null || !$this->policy->shouldAnonymize($class, $type)) {
					// Readable somewhere (not annotated / not masked / exempt entity),
					// so the name must never trigger redaction.
					$readable[$columnName] = true;
				} elseif ($type->isDirectIdentifier()) {
					$identifiers[$columnName] = true;
				}
			}
		}

		return $this->columns = array_diff_key($identifiers, $readable);
	}
}
