<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:industry:regenerate-steps',
    description: 'Regenerate steps for an industry project',
)]
class RegenerateProjectStepsCommand extends Command
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
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
        $io->info("Regenerating steps for: {$productName}");

        $oldCount = $project->getSteps()->count();
        $this->projectService->regenerateSteps($project);
        $newCount = $project->getSteps()->count();

        $io->success("Steps regenerated: {$oldCount} -> {$newCount}");

        return Command::SUCCESS;
    }
}
