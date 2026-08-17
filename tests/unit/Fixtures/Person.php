<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Tests\Fixtures;

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use ADT\DoctrineAnonymization\Attributes\Description;
use Doctrine\ORM\Mapping as ORM;

/**
 * A data subject - nothing is exempt here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'person')]
class Person
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	#[Description('Primary key of the person.')]
	public ?int $id = null;

	/** Same column name as Team::$name, but masked here and readable there. */
	#[ORM\Column(name: 'NAME')]
	#[Anonymize(AnonymizationType::FULL_NAME)]
	#[Description('Full name of the person.')]
	public ?string $name = null;

	#[ORM\Column(name: 'EMAIL')]
	#[Anonymize(AnonymizationType::EMAIL)]
	public ?string $email = null;

	#[ORM\Column(name: 'token')]
	#[Anonymize(AnonymizationType::SECRET)]
	public ?string $token = null;

	#[ORM\Column(name: 'birth_date', type: 'date_immutable', nullable: true)]
	#[Anonymize(AnonymizationType::DATE_OF_BIRTH)]
	public ?\DateTimeImmutable $birthDate = null;

	/** Anonymizable type that is deliberately kept readable for regional reports. */
	#[ORM\Column(name: 'city')]
	#[Anonymize(AnonymizationType::CITY)]
	public ?string $city = null;

	/** Not personal data at all - passes through. */
	#[ORM\Column(name: 'weight', type: 'float', nullable: true)]
	#[Description('Weight in kilograms.')]
	public ?float $weight = null;

	#[ORM\ManyToOne(targetEntity: Team::class)]
	#[ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id')]
	#[Description('Team the person belongs to.')]
	public ?Team $team = null;

	/** Foreign key without a description - only the target table hint is expected. */
	#[ORM\ManyToOne(targetEntity: Team::class)]
	#[ORM\JoinColumn(name: 'backup_team_id', referencedColumnName: 'id')]
	public ?Team $backupTeam = null;
}
