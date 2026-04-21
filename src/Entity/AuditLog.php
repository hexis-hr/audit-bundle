<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Entity;

/**
 * Read-model for audit_log rows. The bundle writes via direct DBAL (DoctrineAuditWriter),
 * not ORM — this class exists only so host apps can query historical rows through the
 * familiar EntityManager API.
 */
class AuditLog
{
    public const SOURCE_PRIMARY = 'primary';
    public const SOURCE_FALLBACK = 'fallback';
    public const SOURCE_REPLAYED = 'replayed';

    private ?int $id = null;
    private string $eventId;
    private string $eventType;
    private ?string $source = null;
    private \DateTimeImmutable $occurredAt;
    private ?string $actorId = null;
    private ?string $actorType = null;
    private ?string $actorFirewall = null;
    private ?string $impersonatorId = null;
    private ?string $targetClass = null;
    private ?string $targetId = null;
    private ?string $action = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    private ?string $requestMethod = null;
    private ?string $requestPath = null;
    private ?string $sessionIdHash = null;
    private ?string $snapshotMode = null;
    /** @var array<string, mixed>|null */
    private ?array $preImage = null;
    /** @var array<string, mixed>|null */
    private ?array $postImage = null;
    /** @var array<string, mixed>|null */
    private ?array $diff = null;
    /** @var array<string, mixed>|null */
    private ?array $context = null;
    private string $sourceOfTruth = self::SOURCE_PRIMARY;
    private ?\DateTimeImmutable $pendingReplayAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getActorType(): ?string
    {
        return $this->actorType;
    }

    public function getActorFirewall(): ?string
    {
        return $this->actorFirewall;
    }

    public function getImpersonatorId(): ?string
    {
        return $this->impersonatorId;
    }

    public function getTargetClass(): ?string
    {
        return $this->targetClass;
    }

    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getRequestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function getRequestPath(): ?string
    {
        return $this->requestPath;
    }

    public function getSessionIdHash(): ?string
    {
        return $this->sessionIdHash;
    }

    public function getSnapshotMode(): ?string
    {
        return $this->snapshotMode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPreImage(): ?array
    {
        return $this->preImage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPostImage(): ?array
    {
        return $this->postImage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDiff(): ?array
    {
        return $this->diff;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    public function getSourceOfTruth(): string
    {
        return $this->sourceOfTruth;
    }

    public function getPendingReplayAt(): ?\DateTimeImmutable
    {
        return $this->pendingReplayAt;
    }
}
