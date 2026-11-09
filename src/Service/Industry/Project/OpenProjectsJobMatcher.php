<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use Psr\Log\LoggerInterface;

class OpenProjectsJobMatcher
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryJobMatcher $jobMatcher,
        private readonly DerivedContributionService $derivedContributionService,
        private readonly LoggerInterface $logger,
        private readonly ProjectJobActivityRecorder $jobActivityRecorder,
    ) {
    }

    public function matchOpenProjectsOf(User $user): void
    {
        foreach ($this->projectRepository->findOpenOfAcceptedParticipant($user) as $project) {
            try {
                $this->jobMatcher->matchEsiJobs($project);
                $this->jobActivityRecorder->recordJobsOf($project);
                foreach ($project->getBomItems() as $line) {
                    if ($line->isManufacturingOrReactionJob()) {
                        $this->derivedContributionService->refreshLine($line);
                    }
                }
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to match jobs of project', [
                    'projectId' => $project->getId()->toRfc4122(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
