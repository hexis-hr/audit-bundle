<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

final class Target
{
    public function __construct(
        public readonly ?string $class = null,
        public readonly ?string $id = null,
        public readonly ?string $label = null,
    ) {
    }

    public static function entity(string $class, string|int|null $id): self
    {
        return new self($class, $id === null ? null : (string) $id);
    }

    public static function free(string $label): self
    {
        return new self(label: $label);
    }

    public function isEmpty(): bool
    {
        return $this->class === null && $this->id === null && $this->label === null;
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'id' => $this->id,
            'label' => $this->label,
        ];
    }
}
