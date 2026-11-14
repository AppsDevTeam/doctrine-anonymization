<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization\Tests;

use ADT\DoctrineAnonymization\AnonymizedViewsGenerator;
use ADT\DoctrineAnonymization\GenerateAnonymizedViewsCommand;
use Codeception\Test\Unit;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The secret salt of the project: once set, the command must not fall back to the public default */
final class GenerateAnonymizedViewsCommandTest extends Unit
{
	public function testEmptyProjectSaltStopsTheCommand(): void
	{
		$command = new GenerateAnonymizedViewsCommand((new \ReflectionClass(AnonymizedViewsGenerator::class))->newInstanceWithoutConstructor());
		$command->setSalt('');
		$tester = new CommandTester($command);

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('salt', $tester->getDisplay());
	}
}
