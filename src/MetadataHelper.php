<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use ADT\DoctrineAnonymization\Attributes\Anonymize;
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
}
