<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\ProjectCockpitInput;
use App\Service\Industry\Project\ProjectLineFulfillments;

final readonly class ProjectCockpitInputFactory
{
    private const array MANAGER_ROLES = [ParticipantRole::Owner, ParticipantRole::Admin];

    public function create(IndustryProject $project, User $user, ?ProjectLineFulfillments $fulfillments = null): ProjectCockpitInput
    {
        $fulfillments ??= ProjectLineFulfillments::none();

        return new ProjectCockpitInput(
            $project->getStatus(),
            $project->getItems()->count(),
            array_values($project->getBomItems()->map(fn (IndustryProjectBomLine $line): CockpitBomLine => $this->toCockpitBomLine($line, $fulfillments))->toArray()),
            $this->soldQuantityByTypeId($project),
            $this->pendingContributionCount($project),
            $this->isManager($user, $project),
        );
    }

    private function toCockpitBomLine(IndustryProjectBomLine $line, ProjectLineFulfillments $fulfillments): CockpitBomLine
    {
        return new CockpitBomLine(
            $line->getId()->toRfc4122(),
            $line->getTypeId(),
            $line->getTypeName(),
            $line->getRequiredQuantity(),
            $fulfillments->fulfilledOf($line),
            $line->isJob(),
            $line->getJobGroup(),
            $line->getRuns(),
            $line->getCountedRuns(),
            $line->getDeliveredRuns(),
        );
    }

    /**
     * @return array<int, int>
     */
    private function soldQuantityByTypeId(IndustryProject $project): array
    {
        $soldQuantityByTypeId = [];

        /** @var IndustryProjectSale $sale */
        foreach ($project->getSales() as $sale) {
            $typeId = $sale->getTypeId();
            $soldQuantityByTypeId[$typeId] = array_key_exists($typeId, $soldQuantityByTypeId)
                ? $soldQuantityByTypeId[$typeId] + $sale->getQuantity()
                : $sale->getQuantity();
        }

        return $soldQuantityByTypeId;
    }

    private function pendingContributionCount(IndustryProject $project): int
    {
        return $project->getContributions()->filter(
            static fn (IndustryProjectContribution $contribution): bool => $contribution->getStatus() === ContributionStatus::Pending,
        )->count();
    }

    private function isManager(User $user, IndustryProject $project): bool
    {
        if ($project->getOwner() === $user) {
            return true;
        }

        return $project->getMembers()->exists(
            static fn (int $key, IndustryProjectParticipant $member): bool => $member->getUser() === $user
                && $member->getStatus() === ParticipantStatus::Accepted
                && \in_array($member->getRole(), self::MANAGER_ROLES, true),
        );
    }
}
