<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Attributes;

use ADT\DoctrineAnonymization\AnonymizationTypeInterface;
use Attribute;

/**
 * Marks an entity property as personal data of the given kind.
 *
 *     #[ORM\Column(name: 'EMAIL')]
 *     #[Anonymize(AnonymizationType::EMAIL)]
 *     public ?string $email = null;
 *
 * Whether the column really gets masked is decided by
 * {@see \ADT\DoctrineAnonymization\AnonymizationPolicy} (type + entity exemption).
 * The masking itself happens in the definition of the generated views
 * ({@see \ADT\DoctrineAnonymization\GenerateAnonymizedViewsCommand}), i.e. in SQL -
 * loaded entities are never rewritten at runtime.
 *
 * Also applicable to association properties (foreign keys); the join column is
 * treated like any other column.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Anonymize
{
	public function __construct(
		public readonly AnonymizationTypeInterface $type,
	) {
	}
}
