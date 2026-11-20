<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
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
        $projects = $this->projectRepository->findOpenOfAcceptedParticipant($user);
        $drafts = array_filter($projects, static fn (IndustryProject $project): bool => $project->getStatus() === ProjectStatus::Draft);
        $launched = array_filter($projects, static fn (IndustryProject $project): bool => $project->getStatus() !== ProjectStatus::Draft);

        foreach ($drafts as $draft) {
            $this->process($draft, fn () => $this->jobMatcher->releaseAutomaticMatches($draft));
        }

        foreach ($launched as $project) {
            $this->process($project, function () use ($project): void {
                $this->jobMatcher->matchEsiJobs($project);
                $this->jobActivityRecorder->recordJobsOf($project);
            });
        }
    }

    private function process(IndustryProject $project, \Closure $matching): void
    {
        try {
            $matching();
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
