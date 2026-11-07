<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Repository\IndustryProjectParticipantRepository;

class ProjectReferenceReader
{
    public const string REFERENCE_MAX_AGE = '1 month';
    private const string ROOT_ACTIVITY = 'manufacturing';

    public function __construct(
        private readonly IndustryProjectParticipantRepository $participantRepository,
        private readonly ProjectDistributionService $distributionService,
    ) {
    }

    /**
     * @return list<ProjectReference>
     */
    public function read(User $user, \DateTimeImmutable $now): array
    {
        $memberships = $this->participantRepository->findAcceptedOnProjectsCompletedBetween(
            $user,
            $now->modify('-' . self::REFERENCE_MAX_AGE),
            $now,
        );

        $references = [];
        foreach ($memberships as $membership) {
            $project = $membership->getProject();
            $completedAt = $project->getCompletedAt();
            $product = $project->getItems()->first();
            if (1 !== $project->getItems()->count() || false === $product || null === $completedAt) {
                continue;
            }

            $productTypeId = $product->getTypeId();
            if (isset($references[$productTypeId])) {
                continue;
            }

            $references[$productTypeId] = $this->referenceOf($project, $productTypeId, $completedAt);
        }

        return array_values($references);
    }

    private function referenceOf(IndustryProject $project, int $productTypeId, \DateTimeImmutable $completedAt): ProjectReference
    {
        $distribution = $this->distributionService->calculateDistribution($project);
        $units = $this->unitsOf($project, $productTypeId);

        return new ProjectReference(
            productTypeId: $productTypeId,
            projectId: $project->getId()->toRfc4122(),
            completedAt: $completedAt,
            units: $units,
            unitCost: null === $units || null === $distribution->totalProjectCost ? null : $distribution->totalProjectCost / $units,
            marginPercent: $distribution->marginPercent,
        );
    }

    private function unitsOf(IndustryProject $project, int $productTypeId): ?int
    {
        foreach ($project->getBomItems() as $line) {
            if ($this->isRootLineOf($line, $productTypeId)) {
                return $line->getRequiredQuantity() > 0 ? $line->getRequiredQuantity() : null;
            }
        }

        return null;
    }

    private function isRootLineOf(IndustryProjectBomLine $line, int $productTypeId): bool
    {
        return $line->isJob()
            && self::ROOT_ACTIVITY === $line->getActivityType()
            && $productTypeId === $line->getTypeId()
            && null === $line->getParentTypeId();
    }
}
