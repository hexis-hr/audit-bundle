<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Attribute;

use Hexis\AuditBundle\Domain\Snapshot;

/**
 * Marks a Doctrine entity as opted-in for audit capture.
 *
 *   #[Auditable(mode: 'changed_fields', ignoreFields: ['updatedAt'])]
 *   class User { ... }
 *
 * Modes:
 *   - none           → diff-only (only the changed field names)
 *   - changed_fields → {field: {old, new}} for every changed field
 *   - full           → serialized pre_image + post_image via Serializer group
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Auditable
{
    /**
     * @param list<string> $ignoreFields  Field names excluded from diff and snapshot.
     * @param list<string> $groups        Serializer groups used for snapshot mode = 'full'.
     * @param ?string $source             Source bucket label shown in the viewer UI. null = bundle default "doctrine".
     */
    public function __construct(
        public readonly string $mode = Snapshot::MODE_NONE,
        public readonly array $ignoreFields = [],
        public readonly array $groups = ['audit:snapshot'],
        public readonly ?string $source = null,
    ) {
        if (!\in_array($mode, [Snapshot::MODE_NONE, Snapshot::MODE_CHANGED_FIELDS, Snapshot::MODE_FULL], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Auditable mode "%s". Allowed: none, changed_fields, full.',
                $mode,
            ));
        }
    }
}
