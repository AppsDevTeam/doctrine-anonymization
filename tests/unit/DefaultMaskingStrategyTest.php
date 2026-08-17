<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Masking\DefaultMaskingStrategy;

class DefaultMaskingStrategyTest extends \Codeception\Test\Unit
{
	private function expression(AnonymizationType $type, bool $mask = false, string $sqlType = 'varchar(128)'): ?string
	{
		return (new DefaultMaskingStrategy())->expression('`t`.`c`', $type, $sqlType, "'salt'", $mask);
	}

	/**
	 * A full mask would kill age statistics, which is usually the whole point of the
	 * report, so only the exact date is dropped.
	 */
	public function testBirthDateKeepsOnlyTheYearEvenInMaskMode(): void
	{
		$expected = 'IF(`t`.`c` IS NULL, NULL, MAKEDATE(YEAR(`t`.`c`), 1))';

		$this->assertSame($expected, $this->expression(AnonymizationType::DATE_OF_BIRTH, mask: false));
		$this->assertSame($expected, $this->expression(AnonymizationType::DATE_OF_BIRTH, mask: true));
	}

	public function testMaskModeFallsThroughToTheGenericMask(): void
	{
		// null = the caller applies its own fixed mask
		$this->assertNull($this->expression(AnonymizationType::EMAIL, mask: true));
		$this->assertNull($this->expression(AnonymizationType::FULL_NAME, mask: true));
	}

	public function testShapePreservingExpressions(): void
	{
		$email = $this->expression(AnonymizationType::EMAIL);
		$this->assertStringContainsString('@anonymized.local', $email);
		$this->assertStringContainsString('SHA2', $email);

		$this->assertStringContainsString("'+999'", $this->expression(AnonymizationType::PHONE));
		$this->assertStringContainsString('LPAD', $this->expression(AnonymizationType::ZIP));
		$this->assertStringContainsString('ROUND(48.5', $this->expression(AnonymizationType::GPS_LATITUDE));
	}

	public function testNullStaysNull(): void
	{
		$this->assertStringStartsWith('IF(`t`.`c` IS NULL, NULL,', $this->expression(AnonymizationType::EMAIL));
	}

	/**
	 * The type key is part of the hash seed, so the same value in two differently
	 * typed columns does not produce the same pseudonym.
	 */
	public function testHashIsSeededWithSaltAndTypeKey(): void
	{
		$expression = $this->expression(AnonymizationType::EMAIL);

		$this->assertStringContainsString("'salt'", $expression);
		$this->assertStringContainsString("'email'", $expression);
	}

	public function testUnknownShapeFallsThroughToTheGenericHash(): void
	{
		// No shape to preserve for a free text column.
		$this->assertNull($this->expression(AnonymizationType::FREE_TEXT));
	}
}
