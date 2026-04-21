<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Doctrine;

use Hexis\AuditBundle\Attribute\Auditable;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Entity\AuditLog;

/**
 * Resolves whether a given entity class is audited, and with what settings.
 *
 * Lookup order:
 *   1. YAML override (audit.doctrine.classes) — always wins.
 *   2. #[Auditable] attribute on the class.
 *   3. Neither → not audited.
 *
 * Bundle-owned entities are hard-coded as never-audited so the listener cannot trigger on
 * rows the bundle itself writes (recursion guard).
 */
final class AuditableRegistry
{
    private const BUNDLE_CLASSES = [
        AuditLog::class,
    ];

    /** @var array<class-string, Auditable|false> resolved lookups are cached */
    private array $cache = [];

    /**
     * @param array<class-string, array{mode?: string, ignore_fields?: list<string>, source?: ?string}> $yamlOverrides
     * @param list<class-string> $additionalIgnored  Host-supplied deny list.
     */
    public function __construct(
        private readonly array $yamlOverrides = [],
        private readonly array $additionalIgnored = [],
    ) {
    }

    public function isAudited(string $class): bool
    {
        return $this->resolve($class) !== false;
    }

    public function settingsFor(string $class): ?Auditable
    {
        $resolved = $this->resolve($class);

        return $resolved === false ? null : $resolved;
    }

    private function resolve(string $class): Auditable|false
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        if (\in_array($class, self::BUNDLE_CLASSES, true) || \in_array($class, $this->additionalIgnored, true)) {
            return $this->cache[$class] = false;
        }

        if (isset($this->yamlOverrides[$class])) {
            $override = $this->yamlOverrides[$class];

            return $this->cache[$class] = new Auditable(
                mode: $override['mode'] ?? Snapshot::MODE_NONE,
                ignoreFields: $override['ignore_fields'] ?? [],
                source: $override['source'] ?? null,
            );
        }

        if (!class_exists($class)) {
            return $this->cache[$class] = false;
        }

        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Auditable::class);

        if ($attributes === []) {
            return $this->cache[$class] = false;
        }

        return $this->cache[$class] = $attributes[0]->newInstance();
    }
}
