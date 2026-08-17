<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\AnonymizationType as T;
use ADT\DoctrineAnonymization\Tests\Fixtures\Person;
use ADT\DoctrineAnonymization\Tests\Fixtures\Team;

class AnonymizationPolicyTest extends BaseTest
{
	public function testDataSubjectIsFullyAnonymized(): void
	{
		$policy = $this->policy();

		$this->assertTrue($policy->shouldAnonymize(Person::class, T::FULL_NAME));
		$this->assertTrue($policy->shouldAnonymize(Person::class, T::EMAIL));
		$this->assertTrue($policy->shouldAnonymize(Person::class, T::SECRET));
	}

	public function testTypesKeptReadableForReporting(): void
	{
		$policy = $this->policy();

		// Coarse location and company identifiers are reporting dimensions.
		$this->assertFalse($policy->shouldAnonymize(Person::class, T::CITY));
		$this->assertFalse($policy->shouldAnonymize(Person::class, T::ZIP));
		$this->assertFalse($policy->shouldAnonymize(Person::class, T::COMPANY_NAME));
	}

	public function testExemptEntityKeepsOnlyListedTypesReadable(): void
	{
		$policy = $this->policy();

		$this->assertTrue($policy->isExempt(Team::class));
		$this->assertFalse($policy->isExempt(Person::class));

		// Listed type stays readable...
		$this->assertFalse($policy->shouldAnonymize(Team::class, T::FULL_NAME));
		// ...everything else is still anonymized.
		$this->assertTrue($policy->shouldAnonymize(Team::class, T::EMAIL));
		$this->assertTrue($policy->shouldAnonymize(Team::class, T::PHONE));
	}

	public function testAlwaysAnonymizeBeatsExemption(): void
	{
		$policy = $this->policy();

		$this->assertTrue($policy->shouldAnonymize(Team::class, T::SECRET));
		$this->assertTrue($policy->shouldAnonymize(Team::class, T::BANK_ACCOUNT));
	}

	public function testExemptionWithoutArgumentsKeepsEverythingReadable(): void
	{
		$policy = $this->policy();
		$class = FullyExemptFixture::class;

		$this->assertTrue($policy->isExempt($class));
		$this->assertFalse($policy->shouldAnonymize($class, T::FULL_NAME));
		$this->assertFalse($policy->shouldAnonymize($class, T::EMAIL));
		// Except secrets, which are masked everywhere.
		$this->assertTrue($policy->shouldAnonymize($class, T::SECRET));
	}

	public function testExemptionIsInheritedFromParent(): void
	{
		$this->assertTrue($this->policy()->isExempt(ChildOfExemptFixture::class));
	}
}

#[ADT\DoctrineAnonymization\Attributes\AnonymizationExempt]
class FullyExemptFixture
{
}

class ChildOfExemptFixture extends FullyExemptFixture
{
}
