<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

/**
 * A kind of personal data that can be marked on an entity property.
 *
 * The set of types is intentionally open: every project has its own domain
 * (health values, insurance numbers, loyalty ids, ...) and the library cannot
 * enumerate them. Implement this interface with your own enum and use it in
 * {@see Attributes\Anonymize}:
 *
 *     enum MyType: string implements AnonymizationTypeInterface
 *     {
 *         case GLYCAEMIA = 'glycaemia';
 *
 *         public function key(): string { return $this->value; }
 *         public function isAnonymized(): bool { return false; }   // real value kept for reporting
 *         public function alwaysAnonymize(): bool { return false; }
 *         public function isDirectIdentifier(): bool { return false; }
 *     }
 *
 * {@see AnonymizationType} is a ready-made enum with the common, domain-neutral
 * types; use it as is, or as a template for your own.
 */
interface AnonymizationTypeInterface
{
	/**
	 * Stable unique key of the type.
	 *
	 * Becomes part of the deterministic hash seed, so two columns of different
	 * types never hash the same value identically. Do not change it once data has
	 * been generated with it, otherwise previously stable pseudonyms change.
	 */
	public function key(): string;

	/**
	 * Is this type subject to anonymization at all?
	 *
	 * Return false for data that must stay readable for reporting (typically
	 * measurements, amounts, coarse location such as city).
	 */
	public function isAnonymized(): bool;

	/**
	 * Is this type masked even on entities exempt from anonymization
	 * ({@see Attributes\AnonymizationExempt})?
	 *
	 * Meant for values that are never a reporting dimension and whose leak is a
	 * security problem on its own - credentials, tokens, bank accounts.
	 */
	public function alwaysAnonymize(): bool;

	/**
	 * Is this type a direct identifier of an individual (name, e-mail, phone, ...)?
	 *
	 * Useful for consumers that need to redact such values from free-form output;
	 * it is deliberately a narrower set than {@see isAnonymized()}.
	 */
	public function isDirectIdentifier(): bool;
}
