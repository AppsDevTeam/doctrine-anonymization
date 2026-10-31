<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use ADT\DoctrineAnonymization\Attributes\Anonymize;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use ReflectionProperty;

/**
 * Small shared helpers for reading attributes off entity properties.
 *
 * @internal
 */
final class MetadataHelper
{
	/**
	 * Finds a property including inherited ones.
	 *
	 * Entities commonly extend a base class and `ReflectionClass::getProperty()`
	 * does not return private properties of parents, so the hierarchy is walked
	 * explicitly.
	 *
	 * @param class-string $class
	 */
	public static function findProperty(string $class, string $propertyName): ?ReflectionProperty
	{
		for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
			if ($reflection->hasProperty($propertyName)) {
				return $reflection->getProperty($propertyName);
			}
		}

		return null;
	}

	/**
	 * Returns the type marked by {@see Anonymize} on the property, or null.
	 *
	 * @param class-string $class
	 */
	public static function readAnonymizeType(string $class, string $propertyName): ?AnonymizationTypeInterface
	{
		$property = self::findProperty($class, $propertyName);
		if ($property === null) {
			return null;
		}

		$attributes = $property->getAttributes(Anonymize::class);
		if (!$attributes) {
			return null;
		}

		/** @var Anonymize $anonymize */
		$anonymize = $attributes[0]->newInstance();

		return $anonymize->type;
	}

	/**
	 * Join table of an owning-side many-to-many association, or null for any other
	 * association. ORM 2 returns the mapping as an array, ORM 3 as objects.
	 *
	 * @return array{name: string, joinColumns: list<string>, inverseJoinColumns: list<string>}|null
	 */
	public static function readJoinTable(ClassMetadata $metadata, string $fieldName): ?array
	{
		if ($metadata->isAssociationInverseSide($fieldName) || !$metadata->isCollectionValuedAssociation($fieldName)) {
			return null;
		}

		$mapping = $metadata->getAssociationMapping($fieldName);
		$joinTable = is_array($mapping) ? ($mapping['joinTable'] ?? null) : ($mapping->joinTable ?? null);
		if ($joinTable === null) {
			return null;
		}

		$names = static fn (iterable $columns): array => array_values(array_map(
			static fn ($column): string => is_array($column) ? $column['name'] : $column->name,
			[...$columns],
		));

		return [
			'name' => is_array($joinTable) ? $joinTable['name'] : $joinTable->name,
			'joinColumns' => $names(is_array($joinTable) ? $joinTable['joinColumns'] ?? [] : $joinTable->joinColumns),
			'inverseJoinColumns' => $names(is_array($joinTable) ? $joinTable['inverseJoinColumns'] ?? [] : $joinTable->inverseJoinColumns),
		];
	}
}
