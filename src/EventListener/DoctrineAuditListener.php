<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\Proxy;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Doctrine\AuditableRegistry;
use Hexis\AuditBundle\Serializer\AuditSnapshotNormalizer;
use Hexis\AuditBundle\Storage\AuditWriter;

/**
 * Captures insertions/updates/deletions from the UnitOfWork into AuditEvents.
 *
 * Flow:
 *   onFlush  — walk UoW scheduled changes, build AuditEvents per audited entity, stage them.
 *   postFlush — hand staged events to the writer. Host transaction already committed at this
 *               point, so an audit write failure cannot roll back host data.
 *
 * Recursion guard: bundle-owned entity classes are excluded in AuditableRegistry. Additionally,
 * the writer itself uses direct DBAL (not ORM) so an audit-row write never re-enters the
 * UoW this listener is attached to.
 *
 * Soft deletes: Gedmo SoftDeleteable turns a remove() into an UPDATE of the deletedAt field inside
 * its own onFlush, taking the entity out of the scheduled deletions. Its preSoftDelete event is
 * recorded as an ENTITY_DELETE with action "soft_delete", whichever onFlush listener runs first.
 *
 * Many-to-many: when only an owning-side collection changes, Doctrine schedules the owner as an
 * update with an empty change set. onFlush reads those changes from the scheduled collection updates
 * and deletions and records them in the diff as {field: {added: [...], removed: [...]}}.
 *
 * References: a related entity appears as {@ref, id}, the id read through its Doctrine mapping. One inserted
 * in the same flush may only get its id from the database after onFlush, so it is staged as a pending
 * placeholder and postFlush fills in the id.
 */
final class DoctrineAuditListener
{
    public const ACTION_SOFT_DELETE = 'soft_delete';

    /** @var list<AuditEvent> events collected in onFlush, flushed in postFlush */
    private array $staged = [];

    /** @var array<int, int> index in $staged of each entity's ENTITY_DELETE event, by spl_object_id */
    private array $stagedDeletions = [];

    /** @var array<int, PendingReference> one placeholder per referenced entity without an id yet, by spl_object_id */
    private array $pendingRefs = [];

    public function __construct(
        private readonly AuditableRegistry $registry,
        private readonly AuditWriter $writer,
        private readonly ContextCollector $contextCollector,
        private readonly ?AuditSnapshotNormalizer $snapshotNormalizer = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $collectionChanges = $this->collectionChanges($uow);

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $event = $this->buildEvent($entity, $em, $uow, EventType::ENTITY_INSERT);
            if ($event !== null) {
                // Insert: identifier may not be assigned until postFlush — defer id capture.
                $this->staged[] = [$event, $entity, true];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $oid = spl_object_id($entity);
            $event = $this->buildEvent($entity, $em, $uow, EventType::ENTITY_UPDATE, collectionChanges: $collectionChanges[$oid][1] ?? []);
            unset($collectionChanges[$oid]);
            if ($event !== null) {
                $this->staged[] = [$event, null, false];
            }
        }

        // clear() followed by replacing the collection leaves its owner out of the scheduled updates.
        foreach ($collectionChanges as [$owner, $changes]) {
            $event = $this->buildEvent($owner, $em, $uow, EventType::ENTITY_UPDATE, collectionChanges: $changes);
            if ($event !== null) {
                $this->staged[] = [$event, null, false];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $event = $this->buildEvent($entity, $em, $uow, EventType::ENTITY_DELETE);
            if ($event !== null) {
                $this->stagedDeletions[spl_object_id($entity)] = \count($this->staged);
                $this->staged[] = [$event, null, false];
            }
        }
    }

    /**
     * Gedmo dispatches this from its onFlush, before it sets deletedAt, so a full-mode pre-image is
     * the entity as it was. If ours ran first the remove() is already staged as a delete and becomes
     * the soft delete; if Gedmo's ran first our onFlush never sees the entity.
     */
    public function preSoftDelete(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        $index = $this->stagedDeletions[spl_object_id($entity)] ?? null;
        if ($index !== null) {
            $this->staged[$index][0] = $this->copyEvent($this->staged[$index][0], action: self::ACTION_SOFT_DELETE);

            return;
        }

        $em = $args->getObjectManager();
        if (!$em instanceof \Doctrine\ORM\EntityManagerInterface) {
            return;
        }

        $event = $this->buildEvent($entity, $em, $em->getUnitOfWork(), EventType::ENTITY_DELETE, self::ACTION_SOFT_DELETE);
        if ($event !== null) {
            $this->staged[] = [$event, null, false];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $hasPendingRefs = $this->pendingRefs !== [];
        $this->pendingRefs = [];
        if ($this->staged === []) {
            return;
        }

        $em = $args->getObjectManager();
        $staged = $this->staged;
        $this->staged = [];
        $this->stagedDeletions = [];

        foreach ($staged as [$event, $entity, $needsIdBackfill]) {
            if ($needsIdBackfill && $entity !== null) {
                $metadata = $em->getClassMetadata($entity::class);
                $ids = $metadata->getIdentifierValues($entity);
                if ($ids !== []) {
                    $event = $this->copyEvent($event, target: new Target(class: $event->target->class, id: (string) reset($ids)));
                }
            }
            if ($hasPendingRefs) {
                $event = $this->copyEvent($event, snapshot: new Snapshot(
                    $event->snapshot->mode,
                    $this->resolveRefs($event->snapshot->diff, $em),
                    $this->resolveRefs($event->snapshot->preImage, $em),
                    $this->resolveRefs($event->snapshot->postImage, $em),
                ));
            }
            $this->writer->write($event);
        }
    }

    public function onClear(): void
    {
        // If the host clears the UoW mid-flight (e.g. rollback) our staged events are stale.
        $this->staged = [];
        $this->stagedDeletions = [];
        $this->pendingRefs = [];
    }

    /**
     * @param array<string, array{added: list<object>, removed: list<object>}> $collectionChanges
     */
    private function buildEvent(object $entity, \Doctrine\ORM\EntityManagerInterface $em, UnitOfWork $uow, EventType $type, ?string $action = null, array $collectionChanges = []): ?AuditEvent
    {
        $class = $entity::class;
        $settings = $this->registry->settingsFor($class);
        if ($settings === null) {
            return null;
        }

        $ignore = array_flip($settings->ignoreFields);
        $mode = $settings->mode;
        $reference = fn (object $related): array|PendingReference => $this->reference($related, $em);

        $diff = null;
        if ($type === EventType::ENTITY_UPDATE && $mode !== Snapshot::MODE_NONE) {
            $diff = [];
            foreach ($this->fieldChanges($uow, $entity) as $field => [$old, $new]) {
                if (isset($ignore[$field])) {
                    continue;
                }
                $diff[$field] = [
                    'old' => $this->scalarize($old, $em),
                    'new' => $this->scalarize($new, $em),
                ];
            }
            // Ignored fields are already left out of $collectionChanges.
            foreach ($collectionChanges as $field => $change) {
                $diff[$field] = [
                    'added' => array_map($reference, $change['added']),
                    'removed' => array_map($reference, $change['removed']),
                ];
            }
            if ($diff === []) {
                return null;
            }
        }

        // Full-mode snapshots.
        //   INSERT → post-image only.
        //   DELETE → pre-image only (entity is about to disappear).
        //   UPDATE → post-image from the current entity, pre-image derived by replaying the change set
        //            in reverse (old values from changeset layered onto the post-image, many-to-many
        //            additions taken out and removals put back). Avoids relying on
        //            UnitOfWork::getOriginalEntityData semantics that can drift across ORM versions.
        $preImage = null;
        $postImage = null;
        if ($mode === Snapshot::MODE_FULL && $this->snapshotNormalizer !== null) {
            if ($type === EventType::ENTITY_UPDATE) {
                $postImage = $this->snapshotNormalizer->normalize($entity, $settings->ignoreFields, $reference);
                $preImage = $postImage;
                foreach ($this->fieldChanges($uow, $entity) as $field => [$old, $_new]) {
                    if (isset($ignore[$field])) {
                        continue;
                    }
                    $preImage[$field] = $this->scalarize($old, $em);
                }
                foreach (array_keys($collectionChanges) as $field) {
                    if (\is_array($preImage[$field] ?? null)) {
                        $preImage[$field] = $this->collectionBefore($preImage[$field], $diff[$field]);
                    }
                }
            } elseif ($type === EventType::ENTITY_DELETE) {
                $preImage = $this->snapshotNormalizer->normalize($entity, $settings->ignoreFields, $reference);
            } elseif ($type === EventType::ENTITY_INSERT) {
                $postImage = $this->snapshotNormalizer->normalize($entity, $settings->ignoreFields, $reference);
            }
        }

        $snapshot = match (true) {
            $mode === Snapshot::MODE_FULL => new Snapshot(Snapshot::MODE_FULL, $diff, $preImage, $postImage),
            $diff !== null => Snapshot::changedFields($diff),
            default => Snapshot::none(),
        };

        $metadata = $em->getClassMetadata($class);
        $ids = $metadata->getIdentifierValues($entity);
        $targetId = $ids === [] ? null : (string) reset($ids);

        return new AuditEvent(
            type: $type,
            actor: $this->contextCollector->collectActor(),
            target: new Target(class: $class, id: $targetId),
            snapshot: $snapshot,
            context: $this->contextCollector->collectContext(),
            action: $action,
            source: $settings->source ?? 'doctrine',
        );
    }

    /**
     * The net elements added to and removed from each audited many-to-many field, per owner. A replaced
     * collection is scheduled twice, the old one for deletion and the new one for update, so both are
     * merged by object identity.
     *
     * @return array<int, array{object, array<string, array{added: list<object>, removed: list<object>}>}> by spl_object_id of the owner
     */
    private function collectionChanges(UnitOfWork $uow): array
    {
        $owners = [];
        $elements = [];
        foreach ($this->scheduledCollectionChanges($uow) as [$owner, $field, $added, $removed]) {
            $oid = spl_object_id($owner);
            $owners[$oid] = $owner;
            foreach ($added as $element) {
                $elements[$oid][$field]['added'][spl_object_id($element)] = $element;
            }
            foreach ($removed as $element) {
                $elements[$oid][$field]['removed'][spl_object_id($element)] = $element;
            }
        }

        $changes = [];
        foreach ($elements as $oid => $fields) {
            foreach ($fields as $field => $change) {
                $added = $change['added'] ?? [];
                $removed = $change['removed'] ?? [];
                $net = [
                    'added' => array_values(array_diff_key($added, $removed)),
                    'removed' => array_values(array_diff_key($removed, $added)),
                ];
                if ($net['added'] !== [] || $net['removed'] !== []) {
                    $changes[$oid][0] = $owners[$oid];
                    $changes[$oid][1][$field] = $net;
                }
            }
        }

        return $changes;
    }

    /**
     * @return iterable<array{object, string, array<object>, array<object>}> owner, field, added and removed elements
     */
    private function scheduledCollectionChanges(UnitOfWork $uow): iterable
    {
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            $field = $this->auditedManyToManyField($collection, $uow);
            if ($field !== null) {
                // Every element goes. clear() resets the snapshot and a collection never loaded has none,
                // so then they are read from the join table, which still holds them at this point.
                $removed = $collection->getSnapshot() ?: $uow->getCollectionPersister($collection->getMapping())->slice($collection, 0);
                yield [$collection->getOwner(), $field, [], $removed];
            }
        }

        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $field = $this->auditedManyToManyField($collection, $uow);
            if ($field !== null) {
                yield [$collection->getOwner(), $field, $collection->getInsertDiff(), $collection->getDeleteDiff()];
            }
        }
    }

    /**
     * The collection's field when its changes belong in an audited update diff: the owning side of a
     * many-to-many (a one-to-many or inverse side is persisted through the other entity), owned by an
     * entity that is neither inserted nor deleted, audited in a mode with a diff, and not ignored.
     */
    private function auditedManyToManyField(PersistentCollection $collection, UnitOfWork $uow): ?string
    {
        $mapping = $collection->getMapping();
        // ORM 3 maps associations as objects, ORM 2 as arrays.
        [$isOwningManyToMany, $field] = $mapping instanceof AssociationMapping
            ? [$mapping->isManyToManyOwningSide(), $mapping->fieldName]
            : [$mapping['type'] === ClassMetadata::MANY_TO_MANY && $mapping['isOwningSide'], $mapping['fieldName']];

        $owner = $collection->getOwner();
        if (!$isOwningManyToMany || $owner === null || $uow->isScheduledForInsert($owner) || $uow->isScheduledForDelete($owner)) {
            return null;
        }

        $settings = $this->registry->settingsFor($owner::class);
        if ($settings === null || $settings->mode === Snapshot::MODE_NONE || \in_array($field, $settings->ignoreFields, true)) {
            return null;
        }

        return $field;
    }

    /**
     * The entity's change set without its to-many fields: for a replaced collection Doctrine puts the old
     * PersistentCollection there instead of an [old, new] pair.
     *
     * @return array<string, array{mixed, mixed}>
     */
    private function fieldChanges(UnitOfWork $uow, object $entity): array
    {
        return array_filter($uow->getEntityChangeSet($entity), \is_array(...));
    }

    /**
     * A normalized collection as it was before the change: the added elements taken out, the removed ones
     * put back. The original order is unknown, so the removed elements go last.
     *
     * @param array<mixed> $after
     * @param array{added: list<mixed>, removed: list<mixed>} $change
     * @return list<mixed>
     */
    private function collectionBefore(array $after, array $change): array
    {
        foreach ($change['added'] as $element) {
            $key = array_search($element, $after, true);
            if ($key !== false) {
                unset($after[$key]);
            }
        }

        return [...array_values($after), ...$change['removed']];
    }

    /**
     * AuditEvent is readonly: rebuild it with the changed parts, carrying every other field over.
     */
    private function copyEvent(AuditEvent $event, ?Target $target = null, ?string $action = null, ?Snapshot $snapshot = null): AuditEvent
    {
        return new AuditEvent(
            type: $event->type,
            actor: $event->actor,
            target: $target ?? $event->target,
            snapshot: $snapshot ?? $event->snapshot,
            context: $event->context,
            action: $action ?? $event->action,
            source: $event->source,
            eventId: $event->eventId,
            occurredAt: $event->occurredAt,
        );
    }


    /**
     * Reduce a UoW change-set value to something safely JSON-encodable. Related entities are
     * represented as their identifier only — we never dereference them to avoid triggering
     * lazy loads during capture.
     */
    private function scalarize(mixed $value, EntityManagerInterface $em): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (\is_array($value)) {
            return array_map(fn ($v) => $this->scalarize($v, $em), $value);
        }

        if (\is_object($value)) {
            return $this->reference($value, $em);
        }

        return (string) $value;
    }

    /**
     * How a related object is recorded in a diff or an image. A Doctrine entity becomes {@ref, id} with the
     * id read through its mapping, so it needs no getId() and a lazy proxy is reported as its entity class;
     * while the id is not assigned yet it becomes a placeholder. Any other object keeps {@ref, id} from its
     * getId() when it has one, else a bare {@ref}: capture runs inside the host's flush, so a getter that
     * throws or an id without a string form must not escape.
     *
     * @return array{'@ref': class-string, id?: mixed}|PendingReference
     */
    private function reference(object $object, EntityManagerInterface $em): array|PendingReference
    {
        $metadata = $this->entityMetadata($object, $em);
        if ($metadata === null) {
            try {
                $id = method_exists($object, 'getId') ? $object->getId() : null;
                if ($id !== null) {
                    return ['@ref' => $object::class, 'id' => is_scalar($id) ? $id : (string) $id];
                }
            } catch (\Throwable) {
                // fall through to the bare reference
            }

            return ['@ref' => $object::class];
        }

        $id = $this->identifier($object, $metadata, $em);

        return $id === null ? $this->pendingRef($object) : ['@ref' => $metadata->getName(), 'id' => $id];
    }

    /**
     * @return ClassMetadata<object>|null null when the object is not a mapped entity
     */
    private function entityMetadata(object $object, EntityManagerInterface $em): ?ClassMetadata
    {
        // The metadata factory resolves proxy class names in getClassMetadata() but not in isTransient().
        $class = $object instanceof Proxy ? get_parent_class($object) : $object::class;
        if ($class === false || $em->getMetadataFactory()->isTransient($class)) {
            return null;
        }

        // Embeddables (not transient in XML or PHP mappings) and mapped superclasses have no identifier.
        $metadata = $em->getClassMetadata($class);

        return $metadata->isEmbeddedClass || $metadata->isMappedSuperclass ? null : $metadata;
    }

    /**
     * The identifier as recorded: the value of a single identifier field, {field: value} for a composite one.
     * A related entity used as identifier (derived identity) contributes its own identifier, any other object
     * its string form. Null while any part of the identifier is not assigned yet.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function identifier(object $entity, ClassMetadata $metadata, EntityManagerInterface $em): mixed
    {
        $values = $metadata->getIdentifierValues($entity);
        if (\count($values) < \count($metadata->getIdentifierFieldNames())) {
            return null;
        }

        foreach ($values as $field => $value) {
            $values[$field] = $this->identifierValue($value, $em);
            if ($values[$field] === null) {
                return null;
            }
        }

        return \count($values) === 1 ? reset($values) : $values;
    }

    private function identifierValue(mixed $value, EntityManagerInterface $em): mixed
    {
        if (!\is_object($value)) {
            return $value;
        }

        $metadata = $this->entityMetadata($value, $em);
        if ($metadata !== null) {
            return $this->identifier($value, $metadata, $em);
        }

        return $value instanceof \Stringable ? (string) $value : $this->scalarize($value, $em);
    }

    /**
     * A reference to an entity without an id yet; postFlush puts the id in once the insert assigned it. The
     * same entity gets the same placeholder, so the diff and the images can still be matched strictly.
     */
    private function pendingRef(object $entity): PendingReference
    {
        return $this->pendingRefs[spl_object_id($entity)] ??= new PendingReference($entity);
    }

    /**
     * Staged values hold only scalars, arrays and PendingReference placeholders, so the placeholders are
     * the only objects met here; application arrays are never inspected for marker keys.
     */
    private function resolveRefs(mixed $value, EntityManagerInterface $em): mixed
    {
        if ($value instanceof PendingReference) {
            $reference = $this->reference($value->entity, $em);

            // An id the flush did not assign after all leaves the reference bare.
            return $reference instanceof PendingReference ? ['@ref' => $value->entity::class] : $reference;
        }

        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->resolveRefs($item, $em), $value);
        }

        return $value;
    }
}
