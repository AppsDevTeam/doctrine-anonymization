<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Attributes;

use Attribute;

/**
 * Human-readable description of a column - what the value means, what its units
 * are, which values are allowed.
 *
 *     #[ORM\Column(name: 'DISABLED')]
 *     #[Description('Account status: 1 = active, 2 = suspended, 3 = deleted. Not a boolean despite the column name.')]
 *     public $status;
 *
 * Read by {@see \ADT\DoctrineAnonymization\SchemaDescriber}. Two reasons this lives
 * next to the anonymization attributes:
 *
 *  - generated views do not carry over MySQL column comments, so the description has
 *    to come from the entity metadata,
 *  - anything consuming the anonymized schema (reporting, an LLM, a new colleague)
 *    only sees column names; without descriptions it guesses - and guesses wrong,
 *    especially on legacy names and foreign keys.
 *
 * Also applicable to association properties, where the description complements the
 * automatically added target table hint.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Description
{
	public function __construct(
		public readonly string $text,
	) {
	}
}
