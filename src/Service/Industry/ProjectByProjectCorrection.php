<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Enum\ProjectCorrectionStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\UnknownTypeName;
use Doctrine\ORM\EntityManagerInterface;

abstract class ProjectByProjectCorrection
{
    public function __construct(
        private readonly IndustryProjectRepository $projectRepository,
        protected readonly EntityManagerInterface $entityManager,
        private readonly IndustryCalculationService $calculationService,
    ) {
    }

    /**
     * @param \Closure(IndustryProject): bool $isSelected
     * @param \Closure(IndustryProject): ProjectCorrectionOutcome $correct
     * @return list<ProjectCorrectionOutcome>
     */
    protected function correctProjects(\Closure $isSelected, \Closure $correct): array
    {
        $outcomes = [];
        $idOfProjectWhoseFailureClosedTheEntityManager = null;

        foreach ($this->projectRepository->findIdsInCreationOrder() as $projectId) {
            $project = $this->projectRepository->find($projectId);
            if ($project === null) {
                continue;
            }

            try {
                if ($isSelected($project)) {
                    $outcomes[] = $idOfProjectWhoseFailureClosedTheEntityManager === null
                        ? $correct($project)
                        : $this->outcomeOf($project, ProjectCorrectionStatus::Failed, reason: 'not processed: the entity manager was closed by the failure of project '.$idOfProjectWhoseFailureClosedTheEntityManager);
                }
            } catch (\Throwable $failure) {
                $outcomes[] = $this->outcomeOf($project, ProjectCorrectionStatus::Failed, reason: $failure->getMessage());
                if (!$this->entityManager->isOpen()) {
                    $idOfProjectWhoseFailureClosedTheEntityManager ??= $projectId->toRfc4122();
                }
            } finally {
                $this->entityManager->clear();
            }
        }

        return $outcomes;
    }

    protected function outcomeOf(IndustryProject $project, ProjectCorrectionStatus $status, ?string $change = null, ?string $reason = null): ProjectCorrectionOutcome
    {
        return new ProjectCorrectionOutcome(
            $project->getId()?->toRfc4122() ?? throw new \LogicException('A project read from the database has no identifier'),
            $this->calculationService->getProjectDisplayName($project) ?? UnknownTypeName::label($project->getProductTypeId()),
            $project->getRuns(),
            $status,
            $change,
            $reason,
        );
    }
}
