<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Controller;

use Hexis\AuditBundle\Storage\AuditReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only viewer over the AuditReader. Backend-agnostic — works with Doctrine or Elasticsearch
 * depending on how the bundle is configured.
 */
final class AuditViewerController extends AbstractController
{
    public function __construct(
        private readonly AuditReader $reader,
    ) {
    }

    #[Route('', name: 'hexis_audit_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted((string) $this->getParameter('audit.ui.role'));

        $filters = [
            'event_type' => $this->stringOrNull($request->query->get('event_type')),
            'source' => $this->stringOrNull($request->query->get('source')),
            'actor_id' => $this->stringOrNull($request->query->get('actor_id')),
            'actor_firewall' => $this->stringOrNull($request->query->get('actor_firewall')),
            'target_class' => $this->stringOrNull($request->query->get('target_class')),
            'target_id' => $this->stringOrNull($request->query->get('target_id')),
            'session_id_hash' => $this->stringOrNull($request->query->get('session_id_hash')),
            'from' => $this->dateOrNull($request->query->get('from')),
            'to' => $this->dateOrNull($request->query->get('to'), endOfDay: true),
            'search' => $this->stringOrNull($request->query->get('q')),
        ];

        $pageSize = (int) $this->getParameter('audit.ui.page_size');
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * $pageSize;

        $result = $this->reader->list($filters, $pageSize, $offset);

        return $this->render('@AuditBundle/viewer/index.html.twig', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'filters_raw' => [
                'event_type' => $request->query->get('event_type', ''),
                'source' => $request->query->get('source', ''),
                'actor_id' => $request->query->get('actor_id', ''),
                'actor_firewall' => $request->query->get('actor_firewall', ''),
                'target_class' => $request->query->get('target_class', ''),
                'target_id' => $request->query->get('target_id', ''),
                'session_id_hash' => $request->query->get('session_id_hash', ''),
                'from' => $request->query->get('from', ''),
                'to' => $request->query->get('to', ''),
                'q' => $request->query->get('q', ''),
            ],
            'page' => $page,
            'page_size' => $pageSize,
            'event_types' => $this->reader->distinctEventTypes(),
            'firewalls' => $this->reader->distinctFirewalls(),
            'sources' => $this->reader->distinctSources(),
        ]);
    }

    #[Route('/event/{eventId}', name: 'hexis_audit_show', methods: ['GET'], requirements: ['eventId' => '[0-9A-Za-z]+'])]
    public function show(string $eventId): Response
    {
        $this->denyAccessUnlessGranted((string) $this->getParameter('audit.ui.role'));

        $row = $this->reader->get($eventId);
        if ($row === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@AuditBundle/viewer/show.html.twig', [
            'row' => $row,
        ]);
    }

    #[Route('/session/{sessionIdHash}', name: 'hexis_audit_session', methods: ['GET'], requirements: ['sessionIdHash' => '[a-f0-9]{64}'])]
    public function session(string $sessionIdHash): Response
    {
        $this->denyAccessUnlessGranted((string) $this->getParameter('audit.ui.role'));

        $rows = $this->reader->sessionEvents($sessionIdHash);

        return $this->render('@AuditBundle/viewer/session.html.twig', [
            'rows' => $rows,
            'session_id_hash' => $sessionIdHash,
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    private function dateOrNull(mixed $value, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value);
            return $endOfDay ? $dt->setTime(23, 59, 59) : $dt;
        } catch (\Throwable) {
            return null;
        }
    }
}
