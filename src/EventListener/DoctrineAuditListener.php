<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
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
 */
final class DoctrineAuditListener
{
    /** @var list<AuditEvent> events collected in onFlush, flushed in postFlush */
    private array $staged = [];

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

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $event = $this->buildEvent($entity, $em, $uow, EventType::ENTITY_INSERT);
            if ($event !== null) {
                // Insert: identifier may not be assigned until postFlush — defer id capture.
                $this->staged[] = [$event, $entity, true];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $event = $this->buildEvent($entity, $em, $uow, EventType::ENTITY_UPDATE);
            if ($event !== null) {
                $this->staged[] = [$event, null, false];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $event = $this->buildEvent($entity, $em, $uow, EventType::ENTITY_DELETE);
            if ($event !== null) {
                $this->staged[] = [$event, null, false];
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->staged === []) {
            return;
        }

        $em = $args->getObjectManager();
        $staged = $this->staged;
        $this->staged = [];

        foreach ($staged as [$event, $entity, $needsIdBackfill]) {
            if ($needsIdBackfill && $entity !== null) {
                $metadata = $em->getClassMetadata($entity::class);
                $ids = $metadata->getIdentifierValues($entity);
                if ($ids !== []) {
                    $event = new AuditEvent(
                        type: $event->type,
                        actor: $event->actor,
                        target: new Target(class: $event->target->class, id: (string) reset($ids)),
                        snapshot: $event->snapshot,
                        context: $event->context,
                        action: $event->action,
                        eventId: $event->eventId,
                        occurredAt: $event->occurredAt,
                    );
                }
            }
            $this->writer->write($event);
        }
    }

    public function onClear(): void
    {
        // If the host clears the UoW mid-flight (e.g. rollback) our staged events are stale.
        $this->staged = [];
    }

    private function buildEvent(object $entity, \Doctrine\ORM\EntityManagerInterface $em, UnitOfWork $uow, EventType $type): ?AuditEvent
    {
        $class = $entity::class;
        $settings = $this->registry->settingsFor($class);
        if ($settings === null) {
            return null;
        }

        $ignore = array_flip($settings->ignoreFields);
        $mode = $settings->mode;

        $diff = null;
        if ($type === EventType::ENTITY_UPDATE && $mode !== Snapshot::MODE_NONE) {
            $changeSet = $uow->getEntityChangeSet($entity);
            if ($changeSet === []) {
                return null;
            }
            $diff = [];
            foreach ($changeSet as $field => [$old, $new]) {
                if (isset($ignore[$field])) {
                    continue;
                }
                $diff[$field] = [
                    'old' => $this->scalarize($old),
                    'new' => $this->scalarize($new),
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
        //            in reverse (old values from changeset layered onto the post-image). Avoids relying
        //            on UnitOfWork::getOriginalEntityData semantics that can drift across ORM versions.
        $preImage = null;
        $postImage = null;
        if ($mode === Snapshot::MODE_FULL && $this->snapshotNormalizer !== null) {
            if ($type === EventType::ENTITY_UPDATE) {
                $postImage = $this->snapshotNormalizer->normalize($entity, $settings->ignoreFields);
                $preImage = $postImage;
                foreach ($uow->getEntityChangeSet($entity) as $field => [$old, $_new]) {
                    if (isset($ignore[$field])) {
                        continue;
                    }
                    $preImage[$field] = $this->scalarize($old);
                }
            } elseif ($type === EventType::ENTITY_DELETE) {
                $preImage = $this->snapshotNormalizer->normalize($entity, $settings->ignoreFields);
            } elseif ($type === EventType::ENTITY_INSERT) {
                $postImage = $this->snapshotNormalizer->normalize($entity, $settings->ignoreFields);
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
            source: $settings->source ?? 'doctrine',
        );
    }


    /**
     * Reduce a UoW change-set value to something safely JSON-encodable. Related entities are
     * represented as their identifier only — we never dereference them to avoid triggering
     * lazy loads during capture.
     */
    private function scalarize(mixed $value): mixed
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
            return array_map(fn ($v) => $this->scalarize($v), $value);
        }

        if (\is_object($value)) {
            // Try common identifier accessors without triggering anything expensive.
            if (method_exists($value, 'getId')) {
                $id = $value->getId();
                if ($id !== null) {
                    return ['@ref' => $value::class, 'id' => is_scalar($id) ? $id : (string) $id];
                }
            }

            return ['@ref' => $value::class];
        }

        return (string) $value;
    }
}
