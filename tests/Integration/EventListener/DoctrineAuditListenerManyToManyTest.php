<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Integration\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Hexis\AuditBundle\Doctrine\AuditableRegistry;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\EventListener\DoctrineAuditListener;
use Hexis\AuditBundle\Serializer\AuditSnapshotNormalizer;
use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Hexis\AuditBundle\Tests\Integration\Fixtures\AuditedGroup;
use Hexis\AuditBundle\Tests\Integration\Fixtures\FullAuditedGroup;
use Hexis\AuditBundle\Tests\Integration\Fixtures\Teacher;
use Hexis\AuditBundle\Tests\Integration\OrmFactory;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\TestCase;

/**
 * Doctrine schedules the owner of a changed many-to-many collection as an update with an empty change set,
 * so the listener reads the change from the scheduled collection updates and deletions instead.
 */
final class DoctrineAuditListenerManyToManyTest extends TestCase
{
    private EntityManager $em;
    private BufferedAuditWriter $buffer;

    protected function setUp(): void
    {
        $this->listenWith(new AuditableRegistry());
    }

    public function testAddedElementIsRecorded(): void
    {
        [$anna, $ben] = $this->teachers('Anna', 'Ben');
        $group = $this->group(AuditedGroup::class, $anna);

        $group->teachers->add($ben);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [self::ref($ben)], 'removed' => []]], $this->updateDiff());
    }

    public function testRemovedElementIsRecorded(): void
    {
        [$anna, $ben] = $this->teachers('Anna', 'Ben');
        $group = $this->group(AuditedGroup::class, $anna, $ben);

        $group->teachers->removeElement($anna);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [], 'removed' => [self::ref($anna)]]], $this->updateDiff());
    }

    public function testCollectionOnlyChangeWritesExactlyOneRow(): void
    {
        [$anna] = $this->teachers('Anna');
        $group = $this->group(AuditedGroup::class);

        $group->teachers->add($anna);
        $this->flush();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(EventType::ENTITY_UPDATE->value, $rows[0]['event_type']);
        self::assertSame(AuditedGroup::class, $rows[0]['target_class']);
        self::assertSame((string) $group->id, $rows[0]['target_id']);
        self::assertSame('changed_fields', $rows[0]['snapshot_mode']);
    }

    public function testFieldAndCollectionChangeShareOneRow(): void
    {
        [$anna, $ben] = $this->teachers('Anna', 'Ben');
        $group = $this->group(AuditedGroup::class, $anna);

        $group->name = 'Butterflies';
        $group->teachers->add($ben);
        $this->flush();

        self::assertSame([
            'name' => ['old' => 'Ladybirds', 'new' => 'Butterflies'],
            'teachers' => ['added' => [self::ref($ben)], 'removed' => []],
        ], $this->updateDiff());
    }

    public function testReplacedCollectionRecordsTheNetChange(): void
    {
        [$anna, $ben, $carla] = $this->teachers('Anna', 'Ben', 'Carla');
        $group = $this->group(AuditedGroup::class, $anna, $ben);

        $group->teachers = new ArrayCollection([$ben, $carla]);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [self::ref($carla)], 'removed' => [self::ref($anna)]]], $this->updateDiff());
    }

    public function testReplacedUnloadedCollectionRecordsTheNetChange(): void
    {
        [$anna, $ben, $carla] = $this->teachers('Anna', 'Ben', 'Carla');
        $groupId = $this->group(AuditedGroup::class, $anna, $ben)->id;
        $this->em->clear();

        // The old collection is never loaded, so Doctrine has no snapshot of it.
        $group = $this->em->find(AuditedGroup::class, $groupId);
        $group->teachers = new ArrayCollection([
            $this->em->find(Teacher::class, $ben->id),
            $this->em->find(Teacher::class, $carla->id),
        ]);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [self::ref($carla)], 'removed' => [self::ref($anna)]]], $this->updateDiff());
    }

    public function testClearedCollectionRecordsTheRemovedElements(): void
    {
        [$anna, $ben] = $this->teachers('Anna', 'Ben');
        $group = $this->group(AuditedGroup::class, $anna, $ben);

        // clear() resets the collection's snapshot.
        $group->teachers->clear();
        $group->teachers->add($ben);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [], 'removed' => [self::ref($anna)]]], $this->updateDiff());
    }

    public function testClearedThenReplacedCollectionIsRecorded(): void
    {
        [$anna, $ben] = $this->teachers('Anna', 'Ben');
        $group = $this->group(AuditedGroup::class, $anna, $ben);

        // A form emptying the field through a setter does this; Doctrine then leaves the group out of the updates.
        $group->teachers->clear();
        $group->teachers = new ArrayCollection();
        $this->flush();

        self::assertSame(['teachers' => ['added' => [], 'removed' => [self::ref($anna), self::ref($ben)]]], $this->updateDiff());
    }

    public function testInverseSideChangeIsNotRecorded(): void
    {
        [$anna] = $this->teachers('Anna');
        $group = $this->group(AuditedGroup::class);

        // Doctrine persists only the owning side, Teacher::$assistedGroups.
        $group->assistants->add($anna);
        $this->flush();

        self::assertSame([], $this->rows());
    }

    public function testIgnoredCollectionFieldWritesNoRow(): void
    {
        $this->listenWith(new AuditableRegistry([
            AuditedGroup::class => ['mode' => 'changed_fields', 'ignore_fields' => ['teachers']],
        ]));
        [$anna] = $this->teachers('Anna');
        $group = $this->group(AuditedGroup::class);

        $group->teachers->add($anna);
        $this->flush();

        self::assertSame([], $this->rows());
    }

    public function testNoneModeWritesOneRowWithoutDiff(): void
    {
        $this->listenWith(new AuditableRegistry([AuditedGroup::class => ['mode' => 'none']]));
        [$anna] = $this->teachers('Anna');
        $group = $this->group(AuditedGroup::class);

        $group->teachers->add($anna);
        $this->flush();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(EventType::ENTITY_UPDATE->value, $rows[0]['event_type']);
        self::assertNull($rows[0]['diff']);
    }

    public function testInsertAndDeleteOfTheOwnerCarryNoCollectionDiff(): void
    {
        [$anna] = $this->teachers('Anna');
        $group = new AuditedGroup();
        $group->teachers->add($anna);
        $this->em->persist($group);
        $this->flush();

        $this->em->remove($group);
        $this->flush();

        $rows = $this->rows();
        self::assertSame([EventType::ENTITY_INSERT->value, EventType::ENTITY_DELETE->value], array_column($rows, 'event_type'));
        self::assertSame([null, null], array_column($rows, 'diff'));
    }

    public function testFullModeImagesShowTheCollectionBeforeAndAfter(): void
    {
        [$anna, $ben, $carla] = $this->teachers('Anna', 'Ben', 'Carla');
        $group = $this->group(FullAuditedGroup::class, $anna, $ben);

        $group->teachers->removeElement($anna);
        $group->teachers->add($carla);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [self::ref($carla)], 'removed' => [self::ref($anna)]]], $this->updateDiff());
        $row = $this->rows()[0];
        $pre = json_decode($row['pre_image'], true);
        $post = json_decode($row['post_image'], true);
        self::assertEqualsCanonicalizing([self::ref($anna), self::ref($ben)], $pre['teachers']);
        self::assertEqualsCanonicalizing([self::ref($ben), self::ref($carla)], $post['teachers']);
        self::assertSame('Ladybirds', $pre['name']);
        self::assertSame('Ladybirds', $post['name']);
    }

    private function listenWith(AuditableRegistry $registry): void
    {
        $this->em = OrmFactory::create();
        $this->buffer = new BufferedAuditWriter(new DoctrineAuditWriter($this->em->getConnection(), SchemaFactory::TABLE));

        $listener = new DoctrineAuditListener(
            registry: $registry,
            writer: $this->buffer,
            contextCollector: $this->stubContextCollector(),
            snapshotNormalizer: new AuditSnapshotNormalizer(),
        );

        $this->em->getEventManager()->addEventListener(['onFlush', 'postFlush', 'onClear'], $listener);
    }

    /**
     * Teacher is not audited; flushed here so every teacher has an id before a group refers to it.
     *
     * @return list<Teacher>
     */
    private function teachers(string ...$names): array
    {
        $teachers = [];
        foreach ($names as $name) {
            $teachers[] = $teacher = new Teacher($name);
            $this->em->persist($teacher);
        }
        $this->em->flush();

        return $teachers;
    }

    /**
     * @template T of AuditedGroup|FullAuditedGroup
     * @param class-string<T> $class
     * @return T a flushed group, with the audit log emptied after its insert
     */
    private function group(string $class, Teacher ...$teachers): object
    {
        $group = new $class();
        $group->name = 'Ladybirds';
        foreach ($teachers as $teacher) {
            $group->teachers->add($teacher);
        }
        $this->em->persist($group);
        $this->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM ' . SchemaFactory::TABLE);

        return $group;
    }

    private function flush(): void
    {
        $this->em->flush();
        $this->buffer->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM ' . SchemaFactory::TABLE . ' ORDER BY id');
    }

    /**
     * @return array<string, mixed> the diff of the only row written, which must be an update
     */
    private function updateDiff(): array
    {
        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(EventType::ENTITY_UPDATE->value, $rows[0]['event_type']);

        return json_decode($rows[0]['diff'], true);
    }

    /**
     * @return array{'@ref': class-string, id: ?int}
     */
    private static function ref(Teacher $teacher): array
    {
        return ['@ref' => Teacher::class, 'id' => $teacher->id];
    }

    private function stubContextCollector(): ContextCollector
    {
        return new class extends ContextCollector {
            public function __construct()
            {
            }

            public function collectContext(): AuditContext
            {
                return AuditContext::empty();
            }

            public function collectActor(?string $firewall = null): Actor
            {
                return new Actor(id: 'test-user');
            }
        };
    }
}
