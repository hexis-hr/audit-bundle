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
use Hexis\AuditBundle\Tests\Integration\Fixtures\Address;
use Hexis\AuditBundle\Tests\Integration\Fixtures\AuditedGroup;
use Hexis\AuditBundle\Tests\Integration\Fixtures\Badge;
use Hexis\AuditBundle\Tests\Integration\Fixtures\FullAuditedGroup;
use Hexis\AuditBundle\Tests\Integration\Fixtures\OpaqueValue;
use Hexis\AuditBundle\Tests\Integration\Fixtures\Teacher;
use Hexis\AuditBundle\Tests\Integration\Fixtures\Term;
use Hexis\AuditBundle\Tests\Integration\OrmFactory;
use Hexis\AuditBundle\Tests\Integration\SchemaFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testNewTeacherAddedInTheSameFlushKeepsItsId(): void
    {
        [$anna] = $this->teachers('Anna');
        $group = $this->group(AuditedGroup::class, $anna);

        // The database assigns Dan's id during this flush, after onFlush has built the diff.
        $dan = $this->unsavedTeacher('Dan');
        $group->teachers->add($dan);
        $this->flush();

        self::assertNotNull($dan->id);
        self::assertSame(['teachers' => ['added' => [self::ref($dan)], 'removed' => []]], $this->updateDiff());
        self::assertStringNotContainsString('@pending', (string) $this->rows()[0]['diff']);
    }

    public function testTwoNewTeachersStayDistinct(): void
    {
        $group = $this->group(AuditedGroup::class);

        $dan = $this->unsavedTeacher('Dan');
        $eva = $this->unsavedTeacher('Eva');
        $group->teachers->add($dan);
        $group->teachers->add($eva);
        $this->flush();

        self::assertNotSame($dan->id, $eva->id);
        self::assertEqualsCanonicalizing([self::ref($dan), self::ref($eva)], $this->updateDiff()['teachers']['added']);
    }

    public function testToOneLinkToANewEntityKeepsItsId(): void
    {
        $group = $this->group(AuditedGroup::class);

        $dan = $this->unsavedTeacher('Dan');
        $group->lead = $dan;
        $this->flush();

        self::assertSame(['lead' => ['old' => null, 'new' => self::ref($dan)]], $this->updateDiff());
    }

    public function testFullModeImagesKeepTheIdOfANewTeacher(): void
    {
        [$anna] = $this->teachers('Anna');
        $group = $this->group(FullAuditedGroup::class, $anna);

        $dan = $this->unsavedTeacher('Dan');
        $group->teachers->add($dan);
        $this->flush();

        self::assertSame(['teachers' => ['added' => [self::ref($dan)], 'removed' => []]], $this->updateDiff());
        $row = $this->rows()[0];
        self::assertSame([self::ref($anna)], json_decode($row['pre_image'], true)['teachers']);
        self::assertEqualsCanonicalizing([self::ref($anna), self::ref($dan)], json_decode($row['post_image'], true)['teachers']);
        self::assertStringNotContainsString('@pending', $row['pre_image'] . $row['post_image']);
    }

    public function testJsonValueContainingPendingKeyIsStoredAsGiven(): void
    {
        $group = $this->group(AuditedGroup::class);

        // Application data that happens to look like the listener's own pending-reference marker.
        $payload = [
            'job' => ['@pending' => 'client-token', '@ref' => 'external-job', 'status' => 'queued'],
            'retry' => ['@pending' => true],
        ];
        $group->payload = $payload;
        $dan = $this->unsavedTeacher('Dan');
        $group->teachers->add($dan);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $this->flush();
        } finally {
            restore_error_handler();
        }

        self::assertSame([
            'payload' => ['old' => null, 'new' => $payload],
            'teachers' => ['added' => [self::ref($dan)], 'removed' => []],
        ], $this->updateDiff());
    }

    public function testCollectionTargetsWithoutGetIdCarryTheirIds(): void
    {
        $this->listenWith(new AuditableRegistry([Teacher::class => ['mode' => 'changed_fields']]));
        [$anna] = $this->teachers('Anna');
        $ladybirds = $this->group(AuditedGroup::class);
        $bees = $this->group(AuditedGroup::class);

        // Teacher owns assistedGroups; AuditedGroup maps its id but has no getId() method.
        $anna->assistedGroups->add($ladybirds);
        $anna->assistedGroups->add($bees);
        $this->flush();

        self::assertSame(['assistedGroups' => ['added' => [self::ref($ladybirds), self::ref($bees)], 'removed' => []]], $this->updateDiff());
    }

    public function testCollectionTargetWithoutGetIdInsertedInTheSameFlushGetsItsId(): void
    {
        $this->listenWith(new AuditableRegistry([Teacher::class => ['mode' => 'changed_fields']]));
        [$anna] = $this->teachers('Anna');
        $this->clearLog();

        $bees = new AuditedGroup();
        $bees->name = 'Bees';
        $this->em->persist($bees);
        $anna->assistedGroups->add($bees);
        $this->flush();

        self::assertNotNull($bees->id);
        self::assertSame(
            [EventType::ENTITY_INSERT->value, EventType::ENTITY_UPDATE->value],
            array_column($this->rows(), 'event_type'),
        );
        self::assertSame(['assistedGroups' => ['added' => [self::ref($bees)], 'removed' => []]], json_decode($this->rows()[1]['diff'], true));
    }

    public function testFullModeImagesReferenceTargetsWithoutGetIdByTheirIds(): void
    {
        $this->listenWith(new AuditableRegistry([Teacher::class => ['mode' => 'full']]));
        [$anna] = $this->teachers('Anna');
        $ladybirds = $this->group(AuditedGroup::class);
        $bees = $this->group(AuditedGroup::class);
        $anna->assistedGroups->add($ladybirds);
        $this->flush();
        $this->clearLog();

        $anna->assistedGroups->add($bees);
        $this->flush();

        self::assertSame(['assistedGroups' => ['added' => [self::ref($bees)], 'removed' => []]], $this->updateDiff());
        $row = $this->rows()[0];
        self::assertSame([self::ref($ladybirds)], json_decode($row['pre_image'], true)['assistedGroups']);
        self::assertSame([self::ref($ladybirds), self::ref($bees)], json_decode($row['post_image'], true)['assistedGroups']);
    }

    public function testCompositeIdentifierIsRecordedByField(): void
    {
        $term = new Term(2026, 'spring');
        $this->em->persist($term);
        $group = $this->group(AuditedGroup::class);

        $group->term = $term;
        $this->flush();

        self::assertSame(
            ['term' => ['old' => null, 'new' => ['@ref' => Term::class, 'id' => ['year' => 2026, 'season' => 'spring']]]],
            $this->updateDiff(),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function opaqueValues(): iterable
    {
        yield 'getId() throws' => [true];
        yield 'getId() has no string form' => [false];
    }

    #[DataProvider('opaqueValues')]
    public function testFailingGetIdOfAValueObjectDoesNotAbortTheFlush(bool $throws): void
    {
        $group = $this->group(FullAuditedGroup::class);

        $group->tag = new OpaqueValue($throws);
        $group->name = 'Butterflies';
        $this->flush();

        self::assertSame(['name' => ['old' => 'Ladybirds', 'new' => 'Butterflies']], $this->updateDiff());
        self::assertSame(['@ref' => OpaqueValue::class], json_decode($this->rows()[0]['post_image'], true)['tag']);
    }

    public function testEmbeddableIsReferencedLikeAnyOtherObject(): void
    {
        $group = $this->group(FullAuditedGroup::class);

        $group->address->street = 'Main Street 1';
        $this->flush();

        $this->updateDiff();
        self::assertSame(['@ref' => Address::class, 'id' => 'Main Street 1'], json_decode($this->rows()[0]['post_image'], true)['address']);
    }

    public function testMappedNonEntityIsReferencedLikeAnyOtherObject(): void
    {
        $group = $this->group(FullAuditedGroup::class);

        $group->tag = new Badge('gold');
        $group->name = 'Butterflies';
        $this->flush();

        $this->updateDiff();
        self::assertSame(['@ref' => Badge::class, 'id' => 'gold'], json_decode($this->rows()[0]['post_image'], true)['tag']);
    }

    public function testReferenceToALazyProxyNamesTheEntityClass(): void
    {
        [$anna, $ben] = $this->teachers('Anna', 'Ben');
        $group = $this->group(AuditedGroup::class);
        $group->lead = $anna;
        $this->flush();
        $this->clearLog();
        $this->em->clear();

        // Loaded again, the group's lead is an uninitialized proxy of Teacher.
        $group = $this->em->find(AuditedGroup::class, $group->id);
        $group->lead = $this->em->find(Teacher::class, $ben->id);
        $this->flush();

        self::assertSame(['lead' => ['old' => self::ref($anna), 'new' => self::ref($ben)]], $this->updateDiff());
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
        $this->flush();

        return $teachers;
    }

    /**
     * Persisted but not flushed: it gets its id from the database in the next flush.
     */
    private function unsavedTeacher(string $name): Teacher
    {
        $teacher = new Teacher($name);
        $this->em->persist($teacher);

        return $teacher;
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
        $this->clearLog();

        return $group;
    }

    private function flush(): void
    {
        $this->em->flush();
        $this->buffer->flush();
    }

    private function clearLog(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM ' . SchemaFactory::TABLE);
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
    private static function ref(Teacher|AuditedGroup $entity): array
    {
        return ['@ref' => $entity::class, 'id' => $entity->id];
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
