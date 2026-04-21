<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

final class Actor
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $type = null,
        public readonly ?string $firewall = null,
        public readonly ?string $impersonatorId = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'firewall' => $this->firewall,
            'impersonator_id' => $this->impersonatorId,
        ];
    }
}
