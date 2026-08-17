<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

use ADT\DoctrineAnonymization\Attributes\AnonymizationExempt;
use ReflectionClass;

/**
 * The single place deciding whether a given entity column gets anonymized.
 *
 * Keep every consumer going through this class - the generator of the views, any
 * export, any redaction of output. As soon as the rule is duplicated somewhere the
 * two copies drift apart, and a column silently stops being masked in one of them.
 *
 * A column is anonymized when BOTH hold:
 *  1. the type is subject to anonymization ({@see AnonymizationTypeInterface::isAnonymized()}),
 *  2. the entity is not exempt for that type ({@see AnonymizationExempt}).
 *
 * Types returning true from {@see AnonymizationTypeInterface::alwaysAnonymize()}
 * (secrets, bank accounts) are masked regardless of any exemption.
 */
class AnonymizationPolicy
{
	/** @var array<class-string, AnonymizationExempt|null> */
	private array $exemptCache = [];

	/**
	 * @param class-string $class entity class the column belongs to
	 */
	public function shouldAnonymize(string $class, AnonymizationTypeInterface $type): bool
	{
		if ($type->alwaysAnonymize()) {
			return true;
		}

		if (!$type->isAnonymized()) {
			return false;
		}

		$exempt = $this->getExempt($class);
		if ($exempt === null) {
			return true;
		}

		// An empty list means every anonymizable type stays readable.
		return $exempt->keepReadable !== [] && !in_array($type, $exempt->keepReadable, true);
	}

	/**
	 * Is the entity (or any of its parents) exempt from anonymization?
	 *
	 * @param class-string $class
	 */
	public function isExempt(string $class): bool
	{
		return $this->getExempt($class) !== null;
	}

	/**
	 * @param class-string $class
	 */
	private function getExempt(string $class): ?AnonymizationExempt
	{
		if (array_key_exists($class, $this->exemptCache)) {
			return $this->exemptCache[$class];
		}

		$exempt = null;
		for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
			$attributes = $reflection->getAttributes(AnonymizationExempt::class);
			if ($attributes) {
				$exempt = $attributes[0]->newInstance();
				break;
			}
		}

		return $this->exemptCache[$class] = $exempt;
	}
}
