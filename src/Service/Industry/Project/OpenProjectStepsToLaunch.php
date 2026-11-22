<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;

class OpenProjectStepsToLaunch
{
    private const string STATUS_TODO = 'todo';

    private const array STAGES_OF_JOBS = ['blueprint', 'component', 'product'];

    private const array SLOT_CATEGORY_OF_ACTIVITY = [
        'manufacturing' => 'manufacturing',
        'reaction' => 'reaction',
        'copy' => 'science',
        'invention' => 'science',
    ];

    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectBomLineRepository $bomLineRepository,
        private readonly ProjectChainLoader $chainLoader,
    ) {
    }

    /**
     * @return list<StepToLaunch>
     */
    public function forUser(User $user): array
    {
        $projects = array_filter(
            $this->projectRepository->findOpenOfAcceptedParticipant($user),
            static fn (IndustryProject $project): bool => ProjectStatus::Draft !== $project->getStatus(),
        );
        usort($projects, static fn (IndustryProject $left, IndustryProject $right): int => $left->getCreatedAt() <=> $right->getCreatedAt());

        $steps = [];
        foreach ($projects as $project) {
            foreach ($this->stepsOf($project) as $step) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * @return list<StepToLaunch>
     */
    private function stepsOf(IndustryProject $project): array
    {
        $bomLines = $this->bomLineRepository->findBy(['project' => $project]);
        $this->bomLineRepository->loadJobMatchesOfProject($project);

        $steps = [];
        foreach ($this->chainLoader->load($project, $bomLines)->nodes as $node) {
            $slotCategory = self::SLOT_CATEGORY_OF_ACTIVITY[$node->activity ?? ''] ?? null;
            if (self::STATUS_TODO !== $node->status || !\in_array($node->stage, self::STAGES_OF_JOBS, true) || null === $slotCategory || null === $node->typeId || null === $node->typeName) {
                continue;
            }

            $steps[] = new StepToLaunch(
                $slotCategory,
                $project->getId()->toRfc4122(),
                $project->getName(),
                $node->typeId,
                $node->typeName,
                $node->runs ?? throw new \UnexpectedValueException(\sprintf('The step %s of project %s has no runs.', $node->id, $project->getId()->toRfc4122())),
            );
        }

        return $steps;
    }
}
