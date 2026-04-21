<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\EventListener;

use Hexis\AuditBundle\Storage\BufferedAuditWriter;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drains the BufferedAuditWriter at the end of the request/command lifecycle — after the
 * host transaction has committed and the response is already flushed to the client.
 */
final class FlushBufferListener
{
    public function __construct(private readonly BufferedAuditWriter $buffer)
    {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -100)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->buffer->flush();
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -100)]
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->buffer->flush();
    }
}
