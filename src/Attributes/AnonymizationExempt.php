<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Attributes;

use ADT\DoctrineAnonymization\AnonymizationTypeInterface;
use Attribute;

/**
 * Marks an ENTITY whose personal columns stay readable, because it is not a data
 * subject but a reporting dimension you report *by* - staff, branch, company.
 *
 * Without arguments all anonymizable types stay readable:
 *
 *     #[AnonymizationExempt]
 *     class Branch { ... }
 *
 * With a list of types only those stay readable and everything else is still
 * anonymized - e.g. keep a staff member's name for "sales by employee" reports,
 * but still mask their private contact details:
 *
 *     #[AnonymizationExempt(AnonymizationType::FULL_NAME, AnonymizationType::FIRST_NAME)]
 *     class User { ... }
 *
 * The exemption never applies to types returning true from
 * {@see AnonymizationTypeInterface::alwaysAnonymize()} (secrets, bank accounts).
 *
 * Evaluated by {@see \ADT\DoctrineAnonymization\AnonymizationPolicy}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AnonymizationExempt
{
	/** @var list<AnonymizationTypeInterface> types kept readable; empty = all of them */
	public readonly array $keepReadable;

	public function __construct(AnonymizationTypeInterface ...$keepReadable)
	{
		$this->keepReadable = array_values($keepReadable);
	}
}
