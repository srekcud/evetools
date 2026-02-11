<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectService;
use App\Service\Sync\IndustryJobSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:industry:match-jobs',
    description: 'Sync ESI jobs and match them to project steps',
)]
class MatchProjectJobsCommand extends Command
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
        private readonly IndustryJobSyncService $jobSyncService,
        private readonly IndustryCalculationService $calculationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('project-id', InputArgument::REQUIRED, 'The project UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectId = $input->getArgument('project-id');

        $project = $this->projectRepository->find($projectId);
        if ($project === null) {
            $io->error("Project not found: {$projectId}");
            return Command::FAILURE;
        }

        $productName = $this->calculationService->getProjectDisplayName($project);
        $io->info("Syncing ESI jobs for: {$productName}");

        // Sync jobs from ESI first
        $this->jobSyncService->resetCorporationTracking();
        $syncedCount = 0;
        foreach ($project->getUser()->getCharacters() as $character) {
            try {
                $this->jobSyncService->syncCharacterJobs($character);
                $syncedCount++;
                $io->writeln("  Synced jobs for: {$character->getName()}");
            } catch (\Throwable $e) {
                $io->warning("Failed to sync {$character->getName()}: {$e->getMessage()}");
            }
        }

        $io->info("Matching jobs to steps...");
        $this->projectService->matchEsiJobs($project);

        // Count matched steps
        $matchedCount = 0;
        $totalCost = 0.0;
        foreach ($project->getSteps() as $step) {
            if ($step->getJobMatches()->count() > 0) {
                $matchedCount++;
                $totalCost += $step->getJobsCost();
            }
        }

        $io->success([
            "Synced {$syncedCount} characters",
            "Matched {$matchedCount} / {$project->getSteps()->count()} steps",
            "Total job cost: " . number_format($totalCost, 0, ',', ' ') . " ISK",
        ]);

        return Command::SUCCESS;
    }
}
