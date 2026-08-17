<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\SchemaDescriber;

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
}
