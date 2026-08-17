<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Tests\Fixtures;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\AnonymizationExempt;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reporting dimension - the name stays readable so reports can group by it,
 * the rest is still anonymized.
 */
#[ORM\Entity]
#[ORM\Table(name: 'team')]
#[AnonymizationExempt(AnonymizationType::FULL_NAME)]
class Team
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\Column(name: 'NAME')]
	#[Anonymize(AnonymizationType::FULL_NAME)]
	public ?string $name = null;

	/** Not in the exemption list, so it gets masked even here. */
	#[ORM\Column(name: 'EMAIL')]
	#[Anonymize(AnonymizationType::EMAIL)]
	public ?string $email = null;

	/** Masked despite the exemption - secrets are never a reporting dimension. */
	#[ORM\Column(name: 'token')]
	#[Anonymize(AnonymizationType::SECRET)]
	public ?string $token = null;
}
