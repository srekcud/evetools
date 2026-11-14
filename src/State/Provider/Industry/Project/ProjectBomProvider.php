<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectBomLineResource;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryProjectRepository;
use App\Entity\IndustryProjectLineReservation;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\JobInstallerName;
use App\Service\Industry\Project\PlanningBlueprintNames;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\ProjectJobLocationResolver;
use App\Service\Industry\Project\PilotName;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectBomLineResource>
 */
class ProjectBomProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectBomLineRepository $bomItemRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly IndustryProjectLineReservationRepository $reservationRepository,
        private readonly ProjectJobLocationResolver $jobLocationResolver,
        private readonly ProjectContainerFulfillment $containerFulfillment,
        private readonly PlanningBlueprintNames $blueprintNames,
    ) {
    }

    /**
     * @return ProjectBomLineResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
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

        $bomItems = $this->bomItemRepository->findBy(['project' => $project]);
        $fulfillments = $this->containerFulfillment->of($project);

        $reservationsByLine = [];
        foreach ($this->reservationRepository->findByProject($project) as $reservation) {
            $reservationsByLine[$reservation->getBomLine()->getId()->toRfc4122()][] = $reservation;
        }

        $locationsByJobId = $this->accessChecker->isAdminOrOwner($user, $project)
            ? $this->jobLocationResolver->locationsOf($project, $this->jobMatchesOf($bomItems))
            : [];

        $blueprintNameByLineId = $this->blueprintNameByLineId($bomItems);

        return array_map(
            fn (IndustryProjectBomLine $item) => $this->mapToResource($item, $project, $user, $reservationsByLine[$item->getId()->toRfc4122()] ?? [], $locationsByJobId, $fulfillments, $blueprintNameByLineId[$item->getId()->toRfc4122()] ?? null),
            $bomItems,
        );
    }

    /**
     * @param IndustryProjectBomLine[] $bomItems
     *
     * @return array<string, string>
     */
    private function blueprintNameByLineId(array $bomItems): array
    {
        $products = [];
        foreach ($bomItems as $item) {
            if ($item->isManufacturingOrReactionJob()) {
                $products[$item->getId()->toRfc4122()] = [
                    'typeId' => $item->getTypeId(),
                    'activity' => IndustryProjectBomLine::ACTIVITY_REACTION === $item->getActivityType() ? 'reaction' : 'manufacturing',
                ];
            }
        }

        return $this->blueprintNames->byKey($products);
    }

    /**
     * @param IndustryProjectBomLine[] $bomItems
     *
     * @return list<IndustryProjectJobMatch>
     */
    private function jobMatchesOf(array $bomItems): array
    {
        $matches = [];
        foreach ($bomItems as $item) {
            foreach ($item->getJobMatches() as $match) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * @param list<IndustryProjectLineReservation> $reservations
     * @param array<int, array{blueprint: array<string, mixed>, output: array<string, mixed>}> $locationsByJobId
     */
    private function mapToResource(IndustryProjectBomLine $item, IndustryProject $project, User $viewer, array $reservations, array $locationsByJobId, ProjectLineFulfillments $fulfillments, ?string $blueprintName): ProjectBomLineResource
    {
        $resource = new ProjectBomLineResource();
        $resource->id = $item->getId()->toString();
        $resource->typeId = $item->getTypeId();
        $resource->typeName = $item->getTypeName();
        $resource->requiredQuantity = $item->getRequiredQuantity();
        $resource->fulfilledQuantity = $fulfillments->fulfilledOf($item);
        $resource->remainingQuantity = $fulfillments->remainingOf($item);
        $resource->fulfillmentPercent = $fulfillments->percentOf($item);
        $resource->containerQuantity = $fulfillments->containerQuantityOf($item);
        $resource->containerStockState = $fulfillments->stateOf($item)->value;
        $resource->estimatedPrice = $item->getEstimatedPrice();
        $resource->estimatedTotal = $item->getEstimatedPrice() !== null
            ? $item->getEstimatedPrice() * $item->getRequiredQuantity()
            : null;
        $resource->estimatedPriceSource = $item->getEstimatedPrice() !== null && !$item->isJob() ? IndustryProjectService::bomPriceSource()->toArray() : null;
        $resource->isJob = $item->isJob();
        $resource->jobGroup = $item->getJobGroup();
        $resource->activityType = $item->getActivityType();
        $resource->blueprintName = $blueprintName;
        $resource->parentTypeId = $item->getParentTypeId();
        $resource->meLevel = $item->getMeLevel();
        $resource->teLevel = $item->getTeLevel();
        $resource->runs = $item->getRuns();
        $resource->isFulfilled = $fulfillments->isFulfilled($item);
        $resource->jobMatches = array_values($item->getJobMatches()->map(
            static function (IndustryProjectJobMatch $match) use ($project, $viewer, $locationsByJobId): array {
                $jobMatch = get_object_vars(ProjectJobMatchResource::fromEntity($match));
                $jobMatch['characterName'] = JobInstallerName::seenBy($match->getCharacterName(), $project, $viewer);
                $jobMatch['locations'] = $locationsByJobId[$match->getEsiJobId()] ?? null;

                return $jobMatch;
            },
        )->toArray());
        $resource->matchedRuns = $item->getMatchedRuns();
        $resource->purchases = array_values($item->getPurchases()->map(
            static function (IndustryProjectPurchase $purchase) use ($project, $viewer): array {
                $buyerUser = ($purchase->getBuyer() ?? $project->getOwnerParticipant())->getUser();

                return [
                    ...get_object_vars(ProjectPurchaseResource::fromEntity($purchase)),
                    'isMine' => $buyerUser === $viewer,
                    'buyerName' => PilotName::of($buyerUser),
                ];
            },
        )->toArray());
        $resource->purchasedQuantity = $item->getPurchasedQuantity();

        $visibleReservations = $fulfillments->isFulfilled($item) ? [] : $reservations;
        $resource->reservations = array_map(
            static fn (IndustryProjectLineReservation $reservation): array => [
                'participantId' => $reservation->getParticipant()->getId()->toRfc4122(),
                'characterName' => PilotName::of($reservation->getParticipant()->getUser()),
                'quantity' => $reservation->getQuantity(),
                'isMine' => $reservation->getParticipant()->getUser()?->getId()?->toRfc4122() === $viewer->getId()?->toRfc4122(),
            ],
            $visibleReservations,
        );
        $resource->reservedQuantity = array_sum(array_map(static fn (IndustryProjectLineReservation $reservation): int => $reservation->getQuantity(), $visibleReservations));
        $resource->freeQuantity = max(0, $resource->remainingQuantity - $resource->reservedQuantity);

        return $resource;
    }
}
