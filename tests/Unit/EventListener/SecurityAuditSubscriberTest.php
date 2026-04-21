<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Tests\Unit\EventListener;

use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\ContextCollector;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\EventListener\SecurityAuditSubscriber;
use Hexis\AuditBundle\Storage\AuditWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

final class SecurityAuditSubscriberTest extends TestCase
{
    private RecordingWriter $writer;
    private ContextCollector $context;

    protected function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->context = new class extends ContextCollector {
            public function __construct()
            {
            }
            public function collectContext(): AuditContext
            {
                return new AuditContext(ipAddress: '10.0.0.1', requestMethod: 'POST', requestPath: '/login');
            }
        };
    }

    public function testLoginSuccessProducesLoginEvent(): void
    {
        $subscriber = $this->subscriber();

        $user = new InMemoryUser('alice', null);
        $passport = new SelfValidatingPassport(new UserBadge('alice', fn () => $user));
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);
        $event = new LoginSuccessEvent(
            $this->dummyAuthenticator(),
            $passport,
            $token,
            new Request(),
            null,
            'main',
        );

        $subscriber->onLoginSuccess($event);

        self::assertCount(1, $this->writer->written);
        $recorded = $this->writer->written[0];
        self::assertSame(EventType::LOGIN, $recorded->type);
        self::assertSame('alice', $recorded->actor->id);
        self::assertSame('main', $recorded->actor->firewall);
    }

    public function testLoginFailureCapturesAttemptedIdentifierButNeverPassword(): void
    {
        $subscriber = $this->subscriber();

        $request = new Request([], ['_username' => 'alice', '_password' => 'supersecret123']);
        $event = new LoginFailureEvent(
            new BadCredentialsException('nope'),
            $this->dummyAuthenticator(),
            $request,
            null,
            'main',
        );

        $subscriber->onLoginFailure($event);

        self::assertCount(1, $this->writer->written);
        $recorded = $this->writer->written[0];
        self::assertSame(EventType::LOGIN_FAILURE, $recorded->type);
        self::assertSame('alice', $recorded->actor->id);
        self::assertSame(BadCredentialsException::class, $recorded->action);

        // Paranoid: the password must not appear in any stringified form of the event.
        $serialized = serialize($recorded);
        self::assertStringNotContainsString('supersecret123', $serialized);
    }

    public function testLogoutProducesLogoutEvent(): void
    {
        $subscriber = $this->subscriber();

        $user = new InMemoryUser('alice', null);
        $token = new UsernamePasswordToken($user, 'main');
        $event = new LogoutEvent(new Request(), $token);

        $subscriber->onLogout($event);

        self::assertCount(1, $this->writer->written);
        self::assertSame(EventType::LOGOUT, $this->writer->written[0]->type);
        self::assertSame('alice', $this->writer->written[0]->actor->id);
    }

    public function testSwitchUserEnter(): void
    {
        $subscriber = $this->subscriber();

        // On enter Symfony dispatches with the new SwitchUserToken already active.
        $adminUser = new InMemoryUser('admin', null);
        $targetUser = new InMemoryUser('bob', null);
        $originalToken = new UsernamePasswordToken($adminUser, 'main');
        $switchToken = new SwitchUserToken($targetUser, 'main', ['ROLE_USER'], $originalToken);

        $request = new Request();
        $request->attributes->set('_firewall_context', 'main');
        $event = new SwitchUserEvent($request, $targetUser, $switchToken);

        $subscriber->onSwitchUser($event);

        self::assertCount(1, $this->writer->written);
        $recorded = $this->writer->written[0];
        self::assertSame(EventType::SWITCH_USER_ENTER, $recorded->type);
        self::assertSame('bob', $recorded->actor->id);
        self::assertSame('admin', $recorded->actor->impersonatorId);
    }

    public function testSwitchUserExit(): void
    {
        $subscriber = $this->subscriber();

        // On exit Symfony dispatches AFTER unwrapping — token is the plain original, target is the original user.
        $adminUser = new InMemoryUser('admin', null);
        $originalToken = new UsernamePasswordToken($adminUser, 'main');

        $request = new Request();
        $request->attributes->set('_firewall_context', 'main');
        $event = new SwitchUserEvent($request, $adminUser, $originalToken);

        $subscriber->onSwitchUser($event);

        self::assertCount(1, $this->writer->written);
        $recorded = $this->writer->written[0];
        self::assertSame(EventType::SWITCH_USER_EXIT, $recorded->type);
        self::assertSame('admin', $recorded->actor->id);
        self::assertNull($recorded->actor->impersonatorId, 'On exit the impersonator is no longer discoverable from the event');
    }

    public function testDisabledEventTypeSkipsCapture(): void
    {
        $subscriber = new SecurityAuditSubscriber(
            $this->writer,
            $this->context,
            enabledEvents: [SecurityAuditSubscriber::EVENT_LOGIN], // failure disabled
        );

        $request = new Request([], ['_username' => 'alice']);
        $event = new LoginFailureEvent(
            new BadCredentialsException('nope'),
            $this->dummyAuthenticator(),
            $request,
            null,
            'main',
        );

        $subscriber->onLoginFailure($event);

        self::assertCount(0, $this->writer->written);
    }

    public function testFirewallFilter(): void
    {
        $subscriber = new SecurityAuditSubscriber(
            $this->writer,
            $this->context,
            enabledFirewalls: ['superadmin'],
        );

        $user = new InMemoryUser('alice', null);
        $passport = new SelfValidatingPassport(new UserBadge('alice', fn () => $user));
        $token = new UsernamePasswordToken($user, 'main');
        $event = new LoginSuccessEvent(
            $this->dummyAuthenticator(),
            $passport,
            $token,
            new Request(),
            null,
            'main',
        );

        $subscriber->onLoginSuccess($event);

        self::assertCount(0, $this->writer->written, 'main firewall must be skipped when only superadmin enabled');
    }

    private function subscriber(): SecurityAuditSubscriber
    {
        return new SecurityAuditSubscriber($this->writer, $this->context);
    }

    private function dummyAuthenticator(): AuthenticatorInterface
    {
        return new class implements AuthenticatorInterface {
            public function supports(Request $request): ?bool
            {
                return false;
            }
            public function authenticate(Request $request): Passport
            {
                throw new \LogicException();
            }
            public function createToken(Passport $passport, string $firewallName): \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
            {
                throw new \LogicException();
            }
            public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?\Symfony\Component\HttpFoundation\Response
            {
                return null;
            }
            public function onAuthenticationFailure(Request $request, \Symfony\Component\Security\Core\Exception\AuthenticationException $exception): ?\Symfony\Component\HttpFoundation\Response
            {
                return null;
            }
        };
    }
}

final class RecordingWriter implements AuditWriter
{
    /** @var list<AuditEvent> */
    public array $written = [];

    public function write(AuditEvent $event): void
    {
        $this->written[] = $event;
    }

    public function writeBatch(iterable $events): void
    {
        foreach ($events as $event) {
            $this->written[] = $event;
        }
    }
}
