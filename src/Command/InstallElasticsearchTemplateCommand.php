<?php

declare(strict_types=1);

namespace Hexis\AuditBundle\Command;

use Hexis\AuditBundle\Storage\Elasticsearch\ElasticsearchClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:install-elasticsearch-template',
    description: 'Install/update the Elasticsearch index template used by the audit writer.',
)]
final class InstallElasticsearchTemplateCommand extends Command
{
    public function __construct(
        private readonly ?ElasticsearchClient $client,
        private readonly string $templateName,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->client === null) {
            $io->error('No Elasticsearch client configured. Set audit.storage.elasticsearch.client in config.');
            return Command::FAILURE;
        }

        $templatePath = \dirname(__DIR__) . '/Resources/elasticsearch/index-template.json';
        $raw = file_get_contents($templatePath);
        if ($raw === false) {
            $io->error('Unable to read template file: ' . $templatePath);
            return Command::FAILURE;
        }

        $template = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->client->putIndexTemplate($this->templateName, $template);
        $io->success(sprintf('Installed Elasticsearch index template "%s"', $this->templateName));

        return Command::SUCCESS;
    }
}
