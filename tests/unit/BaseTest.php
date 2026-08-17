<?php

declare(strict_types=1);

use ADT\DoctrineAnonymization\AnonymizationPolicy;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;

/**
 * Builds an entity manager over the fixtures in tests/unit/Fixtures.
 *
 * Everything under test here only reads Doctrine metadata, so no database is ever
 * touched - the SQLite connection is never even opened.
 */
abstract class BaseTest extends \Codeception\Test\Unit
{
	private static ?EntityManagerInterface $em = null;

	protected function em(): EntityManagerInterface
	{
		if (self::$em !== null) {
			return self::$em;
		}

		$fixtures = __DIR__ . '/Fixtures';
		foreach (glob($fixtures . '/*.php') ?: [] as $file) {
			require_once $file;
		}

		$config = ORMSetup::createAttributeMetadataConfiguration([$fixtures], true);

		// ORM 3 needs either symfony/var-exporter or native lazy objects for proxies.
		if (method_exists($config, 'enableNativeLazyObjects')) {
			$config->enableNativeLazyObjects(true);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

		return self::$em = new EntityManager($connection, $config);
	}

	protected function policy(): AnonymizationPolicy
	{
		return new AnonymizationPolicy();
	}
}
