<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Masking;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\AnonymizationTypeInterface;

/**
 * Masking rules for the built-in {@see AnonymizationType} enum.
 *
 * Only handles cases where a plain mask or hash would destroy something worth
 * keeping; everything else falls through to the generic behaviour (fixed mask, or a
 * deterministic hash shaped by the SQL column type).
 *
 * Reuse it for your own types by extending it and calling `parent::expression()`
 * as the fallback.
 */
class DefaultMaskingStrategy implements MaskingStrategy
{
	public function expression(
		string $quotedColumn,
		AnonymizationTypeInterface $type,
		string $sqlType,
		string $quotedSalt,
		bool $mask,
	): ?string {
		// Reduce a date of birth to the year (YYYY-01-01) instead of masking it away.
		// The exact date is a strong re-identifier, while the year keeps age
		// statistics usable - which is usually the whole point of the report.
		if ($type === AnonymizationType::DATE_OF_BIRTH) {
			return sprintf('IF(%s IS NULL, NULL, MAKEDATE(YEAR(%s), 1))', $quotedColumn, $quotedColumn);
		}

		if ($mask) {
			return null;
		}

		$hash = $this->hash($quotedColumn, $type, $quotedSalt);
		$hashInt = $this->hashInt($hash);

		// Keep the shape of the value so that formats and ranges still look sane.
		$expression = match ($type) {
			AnonymizationType::EMAIL => sprintf("CONCAT(SUBSTRING(%s, 1, 16), '@anonymized.local')", $hash),
			AnonymizationType::PHONE => sprintf("CONCAT('+999', LPAD(%s MOD 100000000, 8, '0'))", $hashInt),
			AnonymizationType::ZIP => sprintf("LPAD(%s MOD 100000, 5, '0')", $hashInt),
			AnonymizationType::GPS_LATITUDE => sprintf('ROUND(48.5 + (%s MOD 2500) / 1000, 6)', $hashInt),
			AnonymizationType::GPS_LONGITUDE => sprintf('ROUND(13.5 + (%s MOD 8500) / 1000, 6)', $hashInt),
			default => null,
		};

		return $expression !== null
			? sprintf('IF(%s IS NULL, NULL, %s)', $quotedColumn, $expression)
			: null;
	}

	/**
	 * Deterministic hash of the value, seeded with the salt and the type key, so the
	 * same input always yields the same output (JOINs and GROUP BY keep working)
	 * while the original value cannot be guessed without the salt.
	 */
	protected function hash(string $quotedColumn, AnonymizationTypeInterface $type, string $quotedSalt): string
	{
		return sprintf(
			"SHA2(CONCAT_WS('|', %s, '%s', CAST(%s AS CHAR)), 256)",
			$quotedSalt,
			$type->key(),
			$quotedColumn,
		);
	}

	/** First 8 hex characters of the hash as a deterministic 32bit number. */
	protected function hashInt(string $hash): string
	{
		return sprintf('CAST(CONV(SUBSTRING(%s, 1, 8), 16, 10) AS UNSIGNED)', $hash);
	}
}
