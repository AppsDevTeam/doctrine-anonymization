<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use ADT\DoctrineAnonymization\Attributes\Description;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionProperty;

/**
 * Builds a map of human-readable column descriptions from entity metadata.
 *
 * Generated views do not carry over MySQL column comments, so anything reading the
 * anonymized schema only sees bare column names. This turns the {@see Description}
 * attributes into `[table => [column => text]]`.
 *
 * Foreign keys are covered as well and always get at least the target table
 * appended, even without a {@see Description}. Without it a consumer has no idea
 * what an FK column means and starts guessing - a legacy `users.DISABLED` holding a
 * status id looks like a boolean, so a filter like `DISABLED = 0` silently returns
 * nothing.
 */
class SchemaDescriber
{
	/** @var array<string, array<string, string>>|null */
	private ?array $descriptions = null;

	public function __construct(
		private readonly EntityManagerInterface $em,
	) {
	}

	/**
	 * @return array<string, array<string, string>> [lowercase table => [column => description]]
	 */
	public function getColumnDescriptions(): array
	{
		if ($this->descriptions !== null) {
			return $this->descriptions;
		}

		$map = [];

		/** @var ClassMetadata $metadata */
		foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
			if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
				continue;
			}

			$table = strtolower($metadata->getTableName());
			$class = $metadata->getReflectionClass()->getName();

			foreach ($metadata->getFieldNames() as $fieldName) {
				// Embedded fields ("address.street") are not supported yet.
				if (str_contains($fieldName, '.')) {
					continue;
				}

				$description = $this->readDescription($class, $fieldName);
				if ($description !== null) {
					$map[$table][$metadata->getColumnName($fieldName)] = $description;
				}
			}

			foreach ($metadata->getAssociationNames() as $fieldName) {
				// Only the owning side with a single join column is a real table column.
				if (
					$metadata->isAssociationInverseSide($fieldName)
					|| !$metadata->isSingleValuedAssociation($fieldName)
					|| !$metadata->isAssociationWithSingleJoinColumn($fieldName)
				) {
					continue;
				}

				$columnName = $metadata->getSingleAssociationJoinColumnName($fieldName);
				if (isset($map[$table][$columnName])) {
					continue;
				}

				$parts = [];

				$description = $this->readDescription($class, $fieldName);
				if ($description !== null) {
					$parts[] = $description;
				}

				try {
					$targetTable = $this->em->getClassMetadata($metadata->getAssociationTargetClass($fieldName))->getTableName();
					$parts[] = sprintf('Foreign key referencing table "%s".', $targetTable);
				} catch (\Throwable) {
					// Target entity may not be mapped; describe without the hint then.
				}

				if ($parts) {
					$map[$table][$columnName] = implode(' ', $parts);
				}
			}
		}

		return $this->descriptions = $map;
	}

	/**
	 * @param class-string $class
	 */
	private function readDescription(string $class, string $propertyName): ?string
	{
		$property = MetadataHelper::findProperty($class, $propertyName);
		if ($property === null) {
			return null;
		}

		$attributes = $property->getAttributes(Description::class);
		if (!$attributes) {
			return null;
		}

		/** @var Description $description */
		$description = $attributes[0]->newInstance();

		return $description->text;
	}
}
