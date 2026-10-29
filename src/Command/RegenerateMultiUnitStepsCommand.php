<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Industry\MultiUnitProjectStepsRegeneration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:industry:regenerate-multi-unit-steps',
    description: 'Regenerate the steps of the active projects whose root step was planned before the runs of the project were read as runs',
)]
class RegenerateMultiUnitStepsCommand extends Command
{
    public function __construct(
        private readonly MultiUnitProjectStepsRegeneration $regeneration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done, do not save');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
