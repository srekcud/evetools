<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Industry\ReactionStepMeLevelReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:industry:reset-reaction-me-levels',
    description: 'Reset to 0 the material efficiency of the reaction steps of the active projects and recalculate their steps',
)]
class ResetReactionMeLevelsCommand extends Command
{
    public function __construct(
        private readonly ReactionStepMeLevelReset $reset,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done, do not save');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');

        return ProjectCorrectionConsoleReport::print(
            new SymfonyStyle($input, $output),
            $this->reset->run($dryRun),
            $dryRun,
            'No project to reset',
        );
    }
}
