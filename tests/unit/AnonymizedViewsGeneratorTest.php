<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\AnonymizedViewsGenerator;

/**
 * Covers the metadata part of the generator - which columns end up masked.
 * Generating the views themselves needs a MySQL server and belongs to an
 * integration test.
 */
class AnonymizedViewsGeneratorTest extends BaseTest
{
	private function tableMap(): array
	{
		return (new AnonymizedViewsGenerator($this->em(), $this->policy()))->buildTableMap();
	}

	public function testMaskedColumnsOfADataSubject(): void
	{
		$person = $this->tableMap()['person'];

		$this->assertSame(AnonymizationType::FULL_NAME, $person['name']);
		$this->assertSame(AnonymizationType::EMAIL, $person['email']);
		$this->assertSame(AnonymizationType::SECRET, $person['token']);
		$this->assertSame(AnonymizationType::DATE_OF_BIRTH, $person['birth_date']);
	}

	public function testReadableColumnsAreNotInTheMap(): void
	{
		$person = $this->tableMap()['person'];

		$this->assertArrayNotHasKey('city', $person);   // anonymizable type kept readable
		$this->assertArrayNotHasKey('weight', $person); // not personal data
		$this->assertArrayNotHasKey('id', $person);
	}

	public function testExemptEntityMasksOnlyWhatIsNotExempt(): void
	{
		$team = $this->tableMap()['team'];

		$this->assertArrayNotHasKey('name', $team); // exempt -> stays readable
		$this->assertSame(AnonymizationType::EMAIL, $team['email']);
		$this->assertSame(AnonymizationType::SECRET, $team['token']); // always masked
	}

	public function testEveryEntityTableIsPresentSoStaleViewsCanBeDetected(): void
	{
		$map = $this->tableMap();

		$this->assertArrayHasKey('person', $map);
		$this->assertArrayHasKey('team', $map);
	}
}
