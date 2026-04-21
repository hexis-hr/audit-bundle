<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

final class AuditContext
{
    /**
     * @param array<string, mixed> $extra Additional host-supplied context. Will be scrubbed before storage.
     */
    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $requestMethod = null,
        public readonly ?string $requestPath = null,
        public readonly ?string $sessionIdHash = null,
        public readonly array $extra = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'request_method' => $this->requestMethod,
            'request_path' => $this->requestPath,
            'session_id_hash' => $this->sessionIdHash,
            'extra' => $this->extra,
        ];
    }
}
