<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Domain;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds an AuditContext from the current request + an Actor from the current security token.
 *
 * The session id is hashed (SHA-256) before it leaves this service — the raw value is never
 * stored. This preserves per-session correlation for workflow visualization while removing
 * the session-hijack vector if the audit store is ever exposed.
 */
class ContextCollector
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly bool $captureIp = true,
        private readonly bool $captureUserAgent = true,
        private readonly bool $captureRequestPath = true,
        private readonly bool $captureSessionId = true,
    ) {
    }

    public function collectContext(): AuditContext
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return AuditContext::empty();
        }

        $sessionHash = null;
        if ($this->captureSessionId && $request->hasSession(true)) {
            $session = $request->getSession();
            if ($session->isStarted()) {
                $sessionHash = hash('sha256', $session->getId());
            }
        }

        return new AuditContext(
            ipAddress: $this->captureIp ? $request->getClientIp() : null,
            userAgent: $this->captureUserAgent ? $request->headers->get('User-Agent') : null,
            requestMethod: $request->getMethod(),
            requestPath: $this->captureRequestPath ? $request->getPathInfo() : null,
            sessionIdHash: $sessionHash,
        );
    }

    public function collectActor(?string $firewall = null): Actor
    {
        if ($this->tokenStorage === null) {
            return Actor::anonymous();
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return Actor::anonymous();
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return Actor::anonymous();
        }

        $impersonatorId = null;
        if ($token instanceof SwitchUserToken) {
            $original = $token->getOriginalToken()->getUser();
            if ($original instanceof UserInterface) {
                $impersonatorId = $original->getUserIdentifier();
            }
        }

        return new Actor(
            id: $user->getUserIdentifier(),
            type: $user::class,
            firewall: $firewall,
            impersonatorId: $impersonatorId,
        );
    }
}
