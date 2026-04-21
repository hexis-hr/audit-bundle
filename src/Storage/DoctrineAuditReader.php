<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DoctrineAuditReader implements AuditReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {
    }

    public function list(array $filters, int $limit, int $offset): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s%s ORDER BY occurred_at DESC LIMIT %d OFFSET %d',
                $this->table,
                $where === '' ? '' : ' WHERE ' . $where,
                max(1, $limit),
                max(0, $offset),
            ),
            $params,
            $types,
        );

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s%s', $this->table, $where === '' ? '' : ' WHERE ' . $where),
            $params,
            $types,
        );

        return [
            'rows' => array_map([$this, 'hydrate'], $rows),
            'total' => $total,
        ];
    }

    public function get(string $eventId): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE event_id = ?', $this->table),
            [$eventId],
            [ParameterType::STRING],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function sessionEvents(string $sessionIdHash, int $limit = 500): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE session_id_hash = ? ORDER BY occurred_at ASC LIMIT %d',
                $this->table,
                max(1, $limit),
            ),
            [$sessionIdHash],
            [ParameterType::STRING],
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function distinctEventTypes(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            sprintf('SELECT DISTINCT event_type FROM %s ORDER BY event_type', $this->table),
        );

        return array_map(static fn ($v) => (string) $v, $rows);
    }

    public function distinctFirewalls(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            sprintf('SELECT DISTINCT actor_firewall FROM %s WHERE actor_firewall IS NOT NULL ORDER BY actor_firewall', $this->table),
        );

        return array_map(static fn ($v) => (string) $v, $rows);
    }

    public function distinctSources(): array
    {
        $rows = $this->connection->fetchFirstColumn(
            sprintf('SELECT DISTINCT source FROM %s WHERE source IS NOT NULL ORDER BY source', $this->table),
        );

        return array_map(static fn ($v) => (string) $v, $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>, 2: list<int>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        $types = [];

        $equalityFilters = [
            'event_type' => 'event_type',
            'source' => 'source',
            'actor_id' => 'actor_id',
            'actor_firewall' => 'actor_firewall',
            'target_class' => 'target_class',
            'target_id' => 'target_id',
            'session_id_hash' => 'session_id_hash',
        ];

        foreach ($equalityFilters as $filterKey => $column) {
            if (($filters[$filterKey] ?? null) !== null && $filters[$filterKey] !== '') {
                $clauses[] = $column . ' = ?';
                $params[] = $filters[$filterKey];
                $types[] = ParameterType::STRING;
            }
        }

        if (!empty($filters['from']) && $filters['from'] instanceof \DateTimeImmutable) {
            $clauses[] = 'occurred_at >= ?';
            $params[] = $filters['from']->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }

        if (!empty($filters['to']) && $filters['to'] instanceof \DateTimeImmutable) {
            $clauses[] = 'occurred_at <= ?';
            $params[] = $filters['to']->format('Y-m-d H:i:s');
            $types[] = ParameterType::STRING;
        }

        if (!empty($filters['search']) && \is_string($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $clauses[] = '(actor_id LIKE ? OR target_class LIKE ? OR target_id LIKE ? OR action LIKE ?)';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
            $types[] = ParameterType::STRING;
        }

        return [implode(' AND ', $clauses), $params, $types];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['occurred_at'] = new \DateTimeImmutable((string) $row['occurred_at']);
        $row['pre_image'] = $this->decode($row['pre_image'] ?? null);
        $row['post_image'] = $this->decode($row['post_image'] ?? null);
        $row['diff'] = $this->decode($row['diff'] ?? null);
        $row['context'] = $this->decode($row['context'] ?? null);

        return $row;
    }

    private function decode(mixed $value): ?array
    {
        if ($value === null || $value === '' || !\is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true);
        return \is_array($decoded) ? $decoded : null;
    }
}
