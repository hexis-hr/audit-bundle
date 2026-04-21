<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Hexis\AuditBundle\Entity\AuditLog;

/**
 * Rewrites the AuditLog entity's mapped table name at metadata-load time so it matches
 * the runtime-configured value. The XML mapping ships with `hexis_audit_log`; hosts that
 * set a custom prefix or full `table` override get the name swapped here before the ORM
 * starts using it.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class TablePrefixListener
{
    public const MAPPED_DEFAULT = 'hexis_audit_log';

    public function __construct(private readonly string $targetTable)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();

        if ($metadata->getName() !== AuditLog::class) {
            return;
        }

        if ($metadata->getTableName() === $this->targetTable) {
            return;
        }

        $metadata->setPrimaryTable(['name' => $this->targetTable]);
    }
}
