<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Unit\Serializer;

use Hexis\AuditBundle\Serializer\AuditSnapshotNormalizer;
use PHPUnit\Framework\TestCase;

final class AuditSnapshotNormalizerTest extends TestCase
{
    public function testScalarsAndNulls(): void
    {
        $normalizer = new AuditSnapshotNormalizer();

        $entity = new class {
            public string $name = 'Alice';
            public ?string $email = null;
            public int $count = 3;
            public bool $enabled = true;
        };

        $result = $normalizer->normalize($entity);

        self::assertSame([
            'name' => 'Alice',
            'email' => null,
            'count' => 3,
            'enabled' => true,
        ], $result);
    }

    public function testIgnoreFields(): void
    {
        $normalizer = new AuditSnapshotNormalizer();

        $entity = new class {
            public string $name = 'Alice';
            public string $internalNote = 'secret';
        };

        $result = $normalizer->normalize($entity, ['internalNote']);

        self::assertSame(['name' => 'Alice'], $result);
    }

    public function testDateTimeAndEnum(): void
    {
        $normalizer = new AuditSnapshotNormalizer();

        $entity = new class {
            public \DateTimeImmutable $createdAt;
            public SampleEnum $role;
            public function __construct()
            {
                $this->createdAt = new \DateTimeImmutable('2026-01-15T10:00:00+00:00');
                $this->role = SampleEnum::Admin;
            }
        };

        $result = $normalizer->normalize($entity);

        self::assertSame('2026-01-15T10:00:00+00:00', $result['createdAt']);
        self::assertSame('admin', $result['role']);
    }

    public function testRelationsCapturedAsIdRefsNotDereferenced(): void
    {
        $normalizer = new AuditSnapshotNormalizer();

        $relation = new class {
            public int $id = 42;
            public function getId(): int
            {
                return $this->id;
            }
        };

        $entity = new class($relation) {
            public function __construct(public object $parent)
            {
            }
        };

        $result = $normalizer->normalize($entity);

        self::assertArrayHasKey('parent', $result);
        self::assertIsArray($result['parent']);
        self::assertSame(42, $result['parent']['id']);
        self::assertArrayHasKey('@ref', $result['parent']);
    }

    public function testCycleSafeOnSelfReference(): void
    {
        $normalizer = new AuditSnapshotNormalizer();

        $entity = new class {
            public int $id = 1;
            public ?object $parent = null;
            public function getId(): int
            {
                return $this->id;
            }
        };
        $entity->parent = $entity; // cycle

        $result = $normalizer->normalize($entity);

        self::assertSame(1, $result['id']);
        self::assertIsArray($result['parent']);
        // The cycle does NOT recurse — parent is captured as a ref, not nested.
        self::assertArrayHasKey('@ref', $result['parent']);
        self::assertSame(1, $result['parent']['id']);
    }

    public function testCollectionOfRelations(): void
    {
        $normalizer = new AuditSnapshotNormalizer();

        $a = new class {
            public int $id = 1;
            public function getId(): int
            {
                return $this->id;
            }
        };
        $b = new class {
            public int $id = 2;
            public function getId(): int
            {
                return $this->id;
            }
        };

        $entity = new class([$a, $b]) {
            public function __construct(public array $items)
            {
            }
        };

        $result = $normalizer->normalize($entity);

        self::assertCount(2, $result['items']);
        self::assertSame(1, $result['items'][0]['id']);
        self::assertSame(2, $result['items'][1]['id']);
    }
}

enum SampleEnum: string
{
    case Admin = 'admin';
    case User = 'user';
}
