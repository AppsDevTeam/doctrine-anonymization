<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\PersonalDataColumns;

class PersonalDataColumnsTest extends BaseTest
{
	private function columns(): array
	{
		return (new PersonalDataColumns($this->em(), $this->policy()))->getDirectIdentifierColumns();
	}

	public function testMaskedDirectIdentifiersAreListed(): void
	{
		$columns = $this->columns();

		// Masked on every entity that has it.
		$this->assertArrayHasKey('token', $columns);
	}

	/**
	 * The decisive case: `NAME` is a masked person name on one entity and a perfectly
	 * readable team name on another. Redacting by column name alone would strip
	 * legitimate values out of reports, so such a name must not be listed.
	 */
	public function testColumnNameReadableElsewhereIsExcluded(): void
	{
		$columns = $this->columns();

		$this->assertArrayNotHasKey('name', $columns);
	}

	public function testEmailMaskedEverywhereIsListed(): void
	{
		// Person::$email is masked and Team::$email is masked too (not in the
		// exemption list), so the column name is safe to redact.
		$this->assertArrayHasKey('email', $this->columns());
	}

	public function testNonIdentifierAndUnmarkedColumnsAreNotListed(): void
	{
		$columns = $this->columns();

		$this->assertArrayNotHasKey('city', $columns);       // anonymizable type, kept readable
		$this->assertArrayNotHasKey('weight', $columns);     // not personal data
		$this->assertArrayNotHasKey('birth_date', $columns); // masked, but not a direct identifier
	}
}
