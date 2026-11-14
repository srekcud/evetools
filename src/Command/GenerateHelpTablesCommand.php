<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ESI\AuthenticationService;
use App\Service\Help\HelpTablesRenderer;
use App\Service\Help\ScheduledSyncReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:help:generate-tables',
    description: 'Write the ESI scopes and sync frequencies help tables from the code'
)]
final class GenerateHelpTablesCommand extends Command
{
    private const string OUTPUT_DIRECTORY_OPTION = 'output-dir';

    public function __construct(
        private readonly HelpTablesRenderer $renderer,
        private readonly ScheduledSyncReader $scheduledSyncReader,
        #[Autowire('%kernel.project_dir%/frontend/src/help')]
        private readonly string $defaultOutputDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            self::OUTPUT_DIRECTORY_OPTION,
            null,
            InputOption::VALUE_REQUIRED,
            'Directory that holds the <language>/data help pages',
            $this->defaultOutputDirectory,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDirectory = rtrim((string) $input->getOption(self::OUTPUT_DIRECTORY_OPTION), '/');
        $filesystem = new Filesystem();

        $pages = $this->renderer->pages(array_values(AuthenticationService::getRequiredScopes()), $this->scheduledSyncReader->read());
        foreach ($pages as $relativePath => $content) {
            $filesystem->dumpFile($outputDirectory.'/'.$relativePath, $content);
            $output->writeln($relativePath);
        }

        return Command::SUCCESS;
    }
}
