<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Industry\ReactionProjectSellPriceRecalculation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:industry:recalculate-reaction-sell-prices',
    description: 'Recalculate the estimated sell price of the active reaction projects created before the given time',
)]
class RecalculateReactionSellPricesCommand extends Command
{
    public function __construct(
        private readonly ReactionProjectSellPriceRecalculation $recalculation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('created-before', null, InputOption::VALUE_REQUIRED, 'Deployment time of the release that fixed the reaction sell price (absolute ISO 8601 date-time with time zone, e.g. 2026-10-28T00:00:00+00:00)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done, do not save');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $createdBeforeOption = $input->getOption('created-before');
        if ($createdBeforeOption === null) {
            $io->error('The --created-before option is required: pass the deployment time of the release that fixed the reaction sell price');

            return Command::FAILURE;
        }

        $createdBefore = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $createdBeforeOption);
        if ($createdBefore === false || \DateTimeImmutable::getLastErrors() !== false) {
            $io->error('Invalid --created-before value: '.$createdBeforeOption);

            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');

        return ProjectCorrectionConsoleReport::print(
            $io,
            $this->recalculation->run($createdBefore, $dryRun),
            $dryRun,
            'No project to recalculate',
        );
    }
}
