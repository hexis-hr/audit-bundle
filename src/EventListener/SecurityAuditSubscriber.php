<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\EventListener;

use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Storage\AuditWriter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Captures Symfony security events into audit rows.
 *
 * Credential safety: this subscriber NEVER serializes the Passport or raw token — it reads
 * only the user identifier, firewall name, and public request context. Even a LoginFailureEvent
 * where the password was wrong does not leak the attempted password.
 */
final class SecurityAuditSubscriber implements EventSubscriberInterface
{
    public const EVENT_LOGIN = 'login';
    public const EVENT_LOGIN_FAILURE = 'login_failure';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_SWITCH_USER = 'switch_user';

    /**
     * @param list<string> $enabledEvents
     * @param list<string> $enabledFirewalls empty = all firewalls
     */
    public function __construct(
        private readonly AuditWriter $writer,
        private readonly ContextCollector $contextCollector,
        private readonly array $enabledEvents = [
            self::EVENT_LOGIN,
            self::EVENT_LOGIN_FAILURE,
            self::EVENT_LOGOUT,
            self::EVENT_SWITCH_USER,
        ],
        private readonly array $enabledFirewalls = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            SwitchUserEvent::class => 'onSwitchUser',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->shouldCapture(self::EVENT_LOGIN, $event->getFirewallName())) {
            return;
        }

        $user = $event->getUser();
        $actor = new Actor(
            id: $user instanceof UserInterface ? $user->getUserIdentifier() : null,
            type: $user instanceof UserInterface ? $user::class : null,
            firewall: $event->getFirewallName(),
        );

        $this->writer->write(new AuditEvent(
            type: EventType::LOGIN,
            actor: $actor,
            target: new Target(),
            snapshot: Snapshot::none(),
            context: $this->contextCollector->collectContext(),
            source: 'security',
        ));
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$this->shouldCapture(self::EVENT_LOGIN_FAILURE, $event->getFirewallName())) {
            return;
        }

        // Intentionally NOT reading $event->getPassport() — we must not surface failed credentials.
        // We surface only the attempted identifier (username) from the request, if available.
        $attemptedIdentifier = null;
        $request = $event->getRequest();
        // Common form field names; host can override via their own subscriber if they use
        // something exotic. Password field is explicitly NOT read.
        foreach (['_username', 'username', 'email', '_email'] as $candidate) {
            $value = $request->request->get($candidate);
            if (is_string($value) && $value !== '') {
                $attemptedIdentifier = mb_substr($value, 0, 64);
                break;
            }
        }

        $actor = new Actor(
            id: $attemptedIdentifier,
            firewall: $event->getFirewallName(),
        );

        $this->writer->write(new AuditEvent(
            type: EventType::LOGIN_FAILURE,
            actor: $actor,
            target: new Target(),
            snapshot: Snapshot::none(),
            context: $this->contextCollector->collectContext(),
            // Short name: FQCNs (e.g. CustomUserMessageAccountStatusException) overflow the 64-char column.
            action: mb_substr((new \ReflectionClass($event->getException()))->getShortName(), 0, 64),
            source: 'security',
        ));
    }

    public function onLogout(LogoutEvent $event): void
    {
        $firewall = $this->firewallFromRequest($event->getRequest());
        if (!$this->shouldCapture(self::EVENT_LOGOUT, $firewall)) {
            return;
        }

        $token = $event->getToken();
        $user = $token?->getUser();

        $actor = new Actor(
            id: $user instanceof UserInterface ? $user->getUserIdentifier() : null,
            type: $user instanceof UserInterface ? $user::class : null,
            firewall: $firewall,
        );

        $this->writer->write(new AuditEvent(
            type: EventType::LOGOUT,
            actor: $actor,
            target: new Target(),
            snapshot: Snapshot::none(),
            context: $this->contextCollector->collectContext(),
            source: 'security',
        ));
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        // Symfony's SwitchUserListener fires this event AFTER swapping the token in storage:
        //   enter → token in storage (and on the event) is a SwitchUserToken wrapping the impersonator's original token
        //   exit  → token on the event is the plain original token, with no wrapper — the impersonation has already ended
        $token = $event->getToken();
        $isEnter = $token instanceof SwitchUserToken;

        $firewall = $this->firewallFromRequest($event->getRequest());
        if (!$this->shouldCapture(self::EVENT_SWITCH_USER, $firewall)) {
            return;
        }

        $impersonator = null;
        if ($isEnter) {
            $original = $token->getOriginalToken()->getUser();
            if ($original instanceof UserInterface) {
                $impersonator = $original->getUserIdentifier();
            }
        }

        $target = $event->getTargetUser();

        $actor = new Actor(
            id: $target->getUserIdentifier(),
            type: $target::class,
            firewall: $firewall,
            impersonatorId: $impersonator,
        );

        $this->writer->write(new AuditEvent(
            type: $isEnter ? EventType::SWITCH_USER_ENTER : EventType::SWITCH_USER_EXIT,
            actor: $actor,
            target: new Target(
                class: $target::class,
                id: $target->getUserIdentifier(),
            ),
            snapshot: Snapshot::none(),
            context: $this->contextCollector->collectContext(),
            source: 'security',
        ));
    }

    private function firewallFromRequest(Request $request): ?string
    {
        // Logout/SwitchUser events don't expose the firewall name. FirewallMap stores the context
        // service id ("security.firewall.map.context.main") on the request; strip it to the name.
        $context = $request->attributes->get('_firewall_context');
        if (!\is_string($context)) {
            return null;
        }

        return mb_substr(str_replace('security.firewall.map.context.', '', $context), 0, 32);
    }

    private function shouldCapture(string $eventName, ?string $firewall): bool
    {
        if (!\in_array($eventName, $this->enabledEvents, true)) {
            return false;
        }

        if ($this->enabledFirewalls === [] || $firewall === null) {
            return true;
        }

        return \in_array($firewall, $this->enabledFirewalls, true);
    }
}
