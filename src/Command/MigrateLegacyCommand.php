<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Hexis\AuditBundle\Legacy\DefaultLegacyRowMapper;
use Hexis\AuditBundle\Legacy\LegacyRowMapper;
use Hexis\AuditBundle\Storage\AuditWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot migration of rows from a host-owned legacy audit table into the bundle's storage.
 *
 * Default mapping targets the common schema (user_id, action, entity_class, entity_id, changes,
 * ip_address, user_agent, method, path, created_at). Hosts with different schemas should pass
 * `--mapper=<service-id>` pointing at a service implementing LegacyRowMapper.
 *
 * Resumable via the id column — re-runs pick up where the previous run left off unless you
 * pass --restart.
 */
#[AsCommand(
    name: 'audit:migrate-legacy',
    description: 'Copy rows from a legacy audit table into the bundle\'s audit storage.',
)]
final class MigrateLegacyCommand extends Command
{
    private const PROGRESS_FILE = 'audit_migrate_legacy.progress';

    public function __construct(
        private readonly ConnectionRegistry $connections,
        private readonly AuditWriter $writer,
        private readonly LegacyRowMapper $defaultMapper,
        private readonly string $progressDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-connection', null, InputOption::VALUE_REQUIRED, 'DBAL connection name to read from.', 'default')
            ->addOption('source-table', null, InputOption::VALUE_REQUIRED, 'Legacy audit table name.', 'audit_log')
            ->addOption('id-column', null, InputOption::VALUE_REQUIRED, 'PK/ordering column on the source table.', 'id')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size per iteration.', '500')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows to migrate this run (0 = unlimited).', '0')
            ->addOption('restart', null, InputOption::VALUE_NONE, 'Ignore the progress file and start from the beginning.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be migrated without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectionName = (string) $input->getOption('source-connection');
        $table = (string) $input->getOption('source-table');
        $idColumn = (string) $input->getOption('id-column');
        $batch = max(1, (int) $input->getOption('batch'));
        $limit = max(0, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');
        $restart = (bool) $input->getOption('restart');

        $connection = $this->connections->getConnection($connectionName);
        \assert($connection instanceof Connection);

        $progressFile = rtrim($this->progressDir, '/') . '/' . self::PROGRESS_FILE;
        $lastId = $restart ? null : $this->readProgress($progressFile);

        $io->title(sprintf(
            'Migrating %s.%s → audit storage (batch=%d, limit=%s, resume_from=%s)',
            $connectionName,
            $table,
            $batch,
            $limit === 0 ? 'unlimited' : (string) $limit,
            $lastId ?? 'start',
        ));

        $total = 0;
        $skipped = 0;
        while (true) {
            $rows = $this->fetchBatch($connection, $table, $idColumn, $lastId, $batch);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (string) $row[$idColumn];
                $event = $this->defaultMapper->map($row);
                if ($event === null) {
                    ++$skipped;
                    continue;
                }
                if (!$dryRun) {
                    $this->writer->write($event);
                }
                ++$total;
                if ($limit > 0 && $total >= $limit) {
                    break 2;
                }
            }

            if (!$dryRun) {
                $this->writeProgress($progressFile, $lastId);
            }
        }

        $io->success(sprintf(
            'Migrated %d row(s), skipped %d%s.',
            $total,
            $skipped,
            $dryRun ? ' (dry-run, nothing written)' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchBatch(Connection $connection, string $table, string $idColumn, ?string $lastId, int $batch): array
    {
        if ($lastId === null) {
            $sql = sprintf('SELECT * FROM %s ORDER BY %s ASC LIMIT %d', $table, $idColumn, $batch);
            return $connection->fetchAllAssociative($sql);
        }

        $sql = sprintf('SELECT * FROM %s WHERE %s > ? ORDER BY %s ASC LIMIT %d', $table, $idColumn, $idColumn, $batch);
        return $connection->fetchAllAssociative($sql, [$lastId]);
    }

    private function readProgress(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = trim((string) file_get_contents($file));
        return $raw === '' ? null : $raw;
    }

    private function writeProgress(string $file, string $lastId): void
    {
        if (!is_dir(\dirname($file))) {
            @mkdir(\dirname($file), 0775, true);
        }
        file_put_contents($file, $lastId);
    }
}
