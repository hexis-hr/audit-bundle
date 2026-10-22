<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Hexis\AuditBundle\Entity\AuditLog;
use Hexis\AuditBundle\Migrations\Version20260421000001;
use Hexis\AuditBundle\Migrations\Version20260421000002;
use Hexis\AuditBundle\Migrations\Version20261022000001;
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

    /**
     * ContextCollector stores getUserIdentifier() as it is, often an email address.
     */
    public function testActorColumnsHold255Characters(): void
    {
        $table = $this->migratedSchema()->getTable('hexis_audit_log');

        self::assertSame(255, $table->getColumn('actor_id')->getLength());
        self::assertSame(255, $table->getColumn('impersonator_id')->getLength());
    }

    public function testDownNarrowsTheActorColumnsBack(): void
    {
        $schema = $this->migratedSchema();
        $this->migration(Version20261022000001::class)->down($schema);

        $table = $schema->getTable('hexis_audit_log');
        self::assertSame(64, $table->getColumn('actor_id')->getLength());
        self::assertSame(64, $table->getColumn('impersonator_id')->getLength());
    }

    public function testWideningSkipsAConnectionWithoutTheTable(): void
    {
        $schema = new Schema();
        $this->migration(Version20261022000001::class)->up($schema);

        self::assertFalse($schema->hasTable('hexis_audit_log'));
    }

    private function migratedSchema(): Schema
    {
        $schema = new Schema();
        foreach ([Version20260421000001::class, Version20260421000002::class, Version20261022000001::class] as $migration) {
            $this->migration($migration)->up($schema);
        }

        return $schema;
    }

    /**
     * @param class-string<AbstractMigration> $class
     */
    private function migration(string $class): AbstractMigration
    {
        // Doctrine Migrations loads these by directory, they are not autoloaded.
        require_once self::bundleDir() . '/src/Resources/migrations/' . substr(strrchr($class, '\\'), 1) . '.php';

        return new $class(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new NullLogger());
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
