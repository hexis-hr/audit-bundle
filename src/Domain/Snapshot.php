<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

final class Snapshot
{
    public const MODE_NONE = 'none';
    public const MODE_CHANGED_FIELDS = 'changed_fields';
    public const MODE_FULL = 'full';

    /**
     * @param array<string, array{old: mixed, new: mixed}>|null $diff
     * @param array<string, mixed>|null $preImage
     * @param array<string, mixed>|null $postImage
     */
    public function __construct(
        public readonly string $mode = self::MODE_NONE,
        public readonly ?array $diff = null,
        public readonly ?array $preImage = null,
        public readonly ?array $postImage = null,
    ) {
    }

    public static function none(): self
    {
        return new self(self::MODE_NONE);
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}> $diff
     */
    public static function changedFields(array $diff): self
    {
        return new self(self::MODE_CHANGED_FIELDS, diff: $diff);
    }

    /**
     * @param array<string, mixed>|null $preImage
     * @param array<string, mixed>|null $postImage
     * @param array<string, array{old: mixed, new: mixed}>|null $diff
     */
    public static function full(?array $preImage, ?array $postImage, ?array $diff = null): self
    {
        return new self(self::MODE_FULL, diff: $diff, preImage: $preImage, postImage: $postImage);
    }

    public function isEmpty(): bool
    {
        return $this->mode === self::MODE_NONE
            && $this->diff === null
            && $this->preImage === null
            && $this->postImage === null;
    }
}
