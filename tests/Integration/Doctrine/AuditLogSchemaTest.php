<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Hexis\AuditBundle\Entity\AuditLog;
use Hexis\AuditBundle\Migrations\Version20260421000001;
use Hexis\AuditBundle\Migrations\Version20260421000002;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The bundle's migrations create the table hosts run against, so the ORM mapping has to describe
 * exactly that table - otherwise every `doctrine:migrations:diff` in the host proposes changes.
 */
final class AuditLogSchemaTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'SQLite' => [DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])->getDatabasePlatform()];
    }

    #[DataProvider('platforms')]
    public function testMappingMatchesTheTableTheMigrationsCreate(AbstractPlatform $platform): void
    {
        $diff = (new Comparator($platform))->compareSchemas($this->migratedSchema(), $this->mappedSchema());

        self::assertSame([], $platform->getAlterSchemaSQL($diff));
    }

    private function migratedSchema(): Schema
    {
        // Doctrine Migrations loads these by directory, they are not autoloaded.
        $dir = self::bundleDir() . '/src/Resources/migrations';
        require_once $dir . '/Version20260421000001.php';
        require_once $dir . '/Version20260421000002.php';

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        foreach ([Version20260421000001::class, Version20260421000002::class] as $migration) {
            (new $migration($connection, new NullLogger()))->up($schema);
        }

        return $schema;
    }

    private function mappedSchema(): Schema
    {
        $config = ORMSetup::createConfiguration(isDevMode: true);
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            self::bundleDir() . '/src/Resources/config/doctrine' => 'Hexis\\AuditBundle\\Entity',
        ]));
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);

        return (new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(AuditLog::class)]);
    }

    private static function bundleDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
