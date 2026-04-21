<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Hexis\AuditBundle\Domain\Actor;
use Hexis\AuditBundle\Domain\AuditContext;
use Hexis\AuditBundle\Domain\AuditEvent;
use Hexis\AuditBundle\Domain\EventType;
use Hexis\AuditBundle\Domain\Snapshot;
use Hexis\AuditBundle\Domain\Target;
use Hexis\AuditBundle\Storage\AuditWriter;
use Hexis\AuditBundle\Storage\DoctrineAuditWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Ulid;

/**
 * Replays rows with source_of_truth='fallback' back into the primary writer. On successful
 * replay the row is marked 'replayed' and pending_replay_at cleared. Idempotent — re-runs
 * only look at rows still marked fallback+pending.
 */
#[AsCommand(
    name: 'audit:drain-fallback',
    description: 'Replay rows from the Doctrine fallback table into the primary audit writer.',
)]
final class DrainFallbackCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly AuditWriter $primary,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to process this run.', '1000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be replayed without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s
                 WHERE source_of_truth = ? AND pending_replay_at IS NOT NULL
                 ORDER BY pending_replay_at ASC
                 LIMIT %d',
                $this->table,
                $limit,
            ),
            [DoctrineAuditWriter::SOURCE_FALLBACK],
            [ParameterType::STRING],
        );

        if ($rows === []) {
            $io->success('No fallback rows pending replay.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Draining %d row(s)%s', \count($rows), $dryRun ? ' (dry-run)' : ''));

        $replayed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if ($dryRun) {
                $io->writeln(sprintf('would replay event_id=%s type=%s', $row['event_id'], $row['event_type']));
                continue;
            }

            try {
                $this->primary->write($this->hydrate($row));
                $this->markReplayed($row['event_id']);
                ++$replayed;
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(sprintf('event_id=%s replay failed: %s: %s', $row['event_id'], $e::class, $e->getMessage()));
                // leave pending_replay_at set so the next drain picks it up
            }
        }

        $io->success(sprintf('Replayed %d row(s), %d failure(s).', $replayed, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditEvent
    {
        $snapshot = new Snapshot(
            mode: $row['snapshot_mode'] ?? Snapshot::MODE_NONE,
            diff: $this->decode($row['diff'] ?? null),
            preImage: $this->decode($row['pre_image'] ?? null),
            postImage: $this->decode($row['post_image'] ?? null),
        );

        $context = new AuditContext(
            ipAddress: $row['ip_address'] ?? null,
            userAgent: $row['user_agent'] ?? null,
            requestMethod: $row['request_method'] ?? null,
            requestPath: $row['request_path'] ?? null,
            sessionIdHash: $row['session_id_hash'] ?? null,
            extra: $this->decode($row['context'] ?? null) ?? [],
        );

        return new AuditEvent(
            type: EventType::from($row['event_type']),
            actor: new Actor(
                id: $row['actor_id'] ?? null,
                type: $row['actor_type'] ?? null,
                firewall: $row['actor_firewall'] ?? null,
                impersonatorId: $row['impersonator_id'] ?? null,
            ),
            target: new Target(
                class: $row['target_class'] ?? null,
                id: $row['target_id'] ?? null,
            ),
            snapshot: $snapshot,
            context: $context,
            action: $row['action'] ?? null,
            eventId: Ulid::fromString($row['event_id']),
            occurredAt: new \DateTimeImmutable($row['occurred_at']),
        );
    }

    private function markReplayed(string $eventId): void
    {
        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s SET source_of_truth = ?, pending_replay_at = NULL WHERE event_id = ?',
                $this->table,
            ),
            [DoctrineAuditWriter::SOURCE_REPLAYED, $eventId],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }

    private function decode(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
