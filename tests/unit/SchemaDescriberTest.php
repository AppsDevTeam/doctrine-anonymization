<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\AnonymizationPolicy;
use ADT\DoctrineAnonymization\SchemaDescriber;
use ADT\DoctrineAnonymization\Tests\Fixtures\Team;

class SchemaDescriberTest extends BaseTest
{
	private function describer(): SchemaDescriber
	{
		return new SchemaDescriber($this->em());
	}

	public function testColumnDescriptionsUseTheColumnNameNotThePropertyName(): void
	{
		$descriptions = $this->describer()->getColumnDescriptions();

		$this->assertArrayHasKey('person', $descriptions);
		$this->assertSame('Full name of the person.', $descriptions['person']['NAME']);
		$this->assertSame('Weight in kilograms.', $descriptions['person']['weight']);
	}

	public function testColumnsWithoutDescriptionAreAbsent(): void
	{
		$descriptions = $this->describer()->getColumnDescriptions();

		$this->assertArrayNotHasKey('EMAIL', $descriptions['person']);
	}

	/**
	 * Without the target table a consumer has no idea what an FK column means and
	 * starts guessing, which is exactly how "WHERE status_id = 0" ends up returning
	 * nothing.
	 */
	public function testForeignKeyGetsTargetTableHint(): void
	{
		$descriptions = $this->describer()->getColumnDescriptions();

		$this->assertSame(
			'Team the person belongs to. Foreign key referencing table "team".',
			$descriptions['person']['team_id'],
		);
	}

	public function testForeignKeyWithoutDescriptionStillGetsTheHint(): void
	{
		$descriptions = $this->describer()->getColumnDescriptions();

		$this->assertSame(
			'Foreign key referencing table "team".',
			$descriptions['person']['backup_team_id'],
		);
	}

	public function testExcludedEntityIsNotDescribed(): void
	{
		$descriptions = (new SchemaDescriber($this->em(), new AnonymizationPolicy([Team::class])))->getColumnDescriptions();

		$this->assertArrayHasKey('person', $descriptions);
		$this->assertArrayNotHasKey('team', $descriptions);
	}

	public function testJoinTableColumnsGetTargetTableHints(): void
	{
		$descriptions = $this->describer()->getColumnDescriptions();

		$this->assertSame('Foreign key referencing table "person".', $descriptions['person_x_team']['person_id']);
		$this->assertSame(
			'Teams the person helps out in. Foreign key referencing table "team".',
			$descriptions['person_x_team']['team_id'],
		);
	}

	public function testJoinTableToAnExcludedEntityIsNotDescribed(): void
	{
		$descriptions = (new SchemaDescriber($this->em(), new AnonymizationPolicy([Team::class])))->getColumnDescriptions();

		$this->assertArrayNotHasKey('person_x_team', $descriptions);
	}
}
