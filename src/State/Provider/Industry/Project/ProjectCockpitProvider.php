<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectCockpitResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\CockpitBomLine;
use App\Service\Industry\Project\CockpitPhase;
use App\Service\Industry\Project\ProjectCockpit;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\Industry\Project\ProjectCockpitInput;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectCockpitResource>
 */
class ProjectCockpitProvider implements ProviderInterface
{
    private const array MANAGER_ROLES = [ParticipantRole::Owner, ParticipantRole::Admin];

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectCockpitCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectCockpitResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $cockpit = $this->calculator->calculate(new ProjectCockpitInput(
            $project->getStatus(),
            $project->getItems()->count(),
            array_values($project->getBomItems()->map($this->toCockpitBomLine(...))->toArray()),
            $this->soldQuantityByTypeId($project),
            $this->pendingContributionCount($project),
            $this->isManager($user, $project),
        ));

        return $this->mapToResource($project, $cockpit);
    }

    private function toCockpitBomLine(IndustryProjectBomLine $line): CockpitBomLine
    {
        return new CockpitBomLine(
            $line->getId()->toRfc4122(),
            $line->getTypeId(),
            $line->getTypeName(),
            $line->getRequiredQuantity(),
            $line->getFulfilledQuantity(),
            $line->isJob(),
            $line->getJobGroup(),
            $line->getRuns(),
            $line->getMatchedRuns(),
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

    private function mapToResource(IndustryProject $project, ProjectCockpit $cockpit): ProjectCockpitResource
    {
        $resource = new ProjectCockpitResource();
        $resource->projectId = $project->getId()->toRfc4122();
        $resource->status = $cockpit->status->value;
        $resource->phases = array_map(
            static fn (CockpitPhase $phase): array => [
                'key' => $phase->key,
                'state' => $phase->state,
                'counter' => ['done' => $phase->counterDone, 'total' => $phase->counterTotal],
                'blockedReason' => $phase->blockedReason === null
                    ? null
                    : ['code' => $phase->blockedReason->code, 'count' => $phase->blockedReason->count],
            ],
            $cockpit->phases,
        );
        $resource->nextAction = [
            'code' => $cockpit->nextAction->code,
            'blocked' => $cockpit->nextAction->blocked,
            'tab' => $cockpit->nextAction->tab,
            'targetStatus' => $cockpit->nextAction->targetStatus,
            'bomLineId' => $cockpit->nextAction->bomLineId,
            'typeId' => $cockpit->nextAction->typeId,
            'typeName' => $cockpit->nextAction->typeName,
            'quantity' => $cockpit->nextAction->quantity,
        ];

        return $resource;
    }
}
