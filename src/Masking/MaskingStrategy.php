<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Masking;

use ADT\DoctrineAnonymization\AnonymizationTypeInterface;

/**
 * Decides the SQL expression that replaces a personal value in a generated view.
 *
 * Implement this when a type needs domain-specific masking. Typical example is a
 * date of birth: a full mask kills age statistics, so it is better reduced to the
 * year only. The library cannot know that about your own types, so it asks the
 * strategy first and falls back to its generic behaviour.
 */
interface MaskingStrategy
{
	/**
	 * @param string $quotedColumn quoted column reference, e.g. `` `t`.`DATE_OF_BIRTH` ``
	 * @param string $sqlType column type as reported by the database, e.g. "varchar(128)"
	 * @param string $quotedSalt quoted salt for deterministic hashing
	 * @param bool $mask whether the caller asked for a fixed mask instead of realistic values
	 * @return string|null SQL expression including NULL handling, or null to use the default
	 */
	public function expression(
		string $quotedColumn,
		AnonymizationTypeInterface $type,
		string $sqlType,
		string $quotedSalt,
		bool $mask,
	): ?string;
}
