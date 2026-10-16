<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Hexis\AuditBundle\AuditBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Boots the bundle the way a host application does, over in-memory SQLite.
 *
 * ENV_DOCTRINE stores audit rows with Doctrine only (the README configuration).
 * ENV_ELASTICSEARCH makes Elasticsearch (InMemoryElasticsearchClient) the primary with the Doctrine fallback.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ENV_DOCTRINE = 'test';
    public const ENV_ELASTICSEARCH = 'test_es';
    public const RETENTION_DAYS = 90;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new AuditBundle();
    }

    /**
     * Outside the checkout: Symfony 7.4+ debug kernels write config/reference.php into the project dir.
     */
    public function getProjectDir(): string
    {
        return $this->varDir();
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'audit-bundle-test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'uid' => ['default_uuid_version' => 7, 'time_based_uuid_version' => 7],
        ]);

        // A debug kernel's default logger writes every query and event to stderr.
        $container->services()->set('logger', NullLogger::class);

        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => [
                'mappings' => [
                    'Fixtures' => [
                        'type' => 'attribute',
                        'dir' => \dirname(__DIR__) . '/Fixtures',
                        'prefix' => 'Hexis\\AuditBundle\\Tests\\Integration\\Fixtures',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $container->extension('audit', [
            'enabled' => true,
            'environments' => [self::ENV_DOCTRINE, self::ENV_ELASTICSEARCH],
            'security' => [
                'enabled' => true,
                'events' => ['login', 'login_failure', 'logout', 'switch_user'],
                'firewalls' => null,
            ],
            'doctrine' => [
                'enabled' => true,
                'entity_managers' => ['default'],
            ],
            'storage' => [
                'primary' => 'doctrine',
                'fallback' => null,
                'doctrine' => [
                    'connection' => 'default',
                    'entity_manager' => null,
                    'table_prefix' => 'hexis_audit_',
                    'table' => null,
                    'retention_days' => self::RETENTION_DAYS,
                ],
            ],
            'snapshots' => [
                'default_mode' => 'none',
                'serializer_group' => 'audit:snapshot',
            ],
            'context' => [
                'capture_ip' => true,
                'capture_user_agent' => true,
                'capture_request_path' => true,
                'capture_session_id' => true,
            ],
        ]);

        if ($this->environment !== self::ENV_ELASTICSEARCH) {
            return;
        }

        $container->services()->set(InMemoryElasticsearchClient::class)->public();

        $container->extension('audit', [
            'storage' => [
                'primary' => 'elasticsearch',
                'fallback' => 'doctrine',
                'elasticsearch' => [
                    'client' => InMemoryElasticsearchClient::class,
                    // Not the default "audit-%Y.%m": AuditBundle passes the pattern unescaped to the
                    // ElasticsearchAuditWriter definition, so strftime tokens fail as parameter references.
                    'index' => 'audit',
                ],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    private function varDir(): string
    {
        // Per checkout and Symfony version, so a container compiled for another install is never reused.
        return sys_get_temp_dir() . '/hexis-audit-bundle-' . md5(__DIR__) . '/' . self::VERSION;
    }
}
