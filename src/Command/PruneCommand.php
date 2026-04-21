<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes rows from the Doctrine audit table older than the configured retention window.
 * Does NOT touch the Elasticsearch index — handle ES retention via ILM on the cluster side.
 */
#[AsCommand(
    name: 'audit:prune',
    description: 'Delete rows from the bundle\'s audit table older than the configured retention window.',
)]
final class PruneCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly int $defaultRetentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Override retention window in days.', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $input->getOption('older-than-days');
        $days = $days === null ? $this->defaultRetentionDays : max(1, (int) $days);
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days));
        $dryRun = (bool) $input->getOption('dry-run');

        $count = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE occurred_at < ?', $this->table),
            [$threshold->format('Y-m-d H:i:s')],
            [ParameterType::STRING],
        );

        if ($count === 0) {
            $io->success(sprintf('No rows older than %d days.', $days));
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(sprintf('Would delete %d row(s) older than %d days (dry-run).', $count, $days));
            return Command::SUCCESS;
        }

        $deleted = (int) $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE occurred_at < ?', $this->table),
            [$threshold->format('Y-m-d H:i:s')],
            [ParameterType::STRING],
        );

        $io->success(sprintf('Deleted %d row(s) older than %d days.', $deleted, $days));
        return Command::SUCCESS;
    }
}
