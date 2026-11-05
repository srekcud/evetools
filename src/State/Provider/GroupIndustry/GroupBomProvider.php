<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryBomResource;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\ApiResource\Industry\ProjectPurchaseResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectPurchase;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<GroupIndustryBomResource>
 */
class GroupBomProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectBomLineRepository $bomItemRepository,
        private readonly GroupProjectAccessChecker $accessChecker,
    ) {
    }

    /**
     * @return GroupIndustryBomResource[]
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
        if ($project === null || $project->isPlannedBySteps()) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $bomItems = $this->bomItemRepository->findBy(['project' => $project]);

        return array_map(
            fn (IndustryProjectBomLine $item) => $this->mapToResource($item),
            $bomItems,
        );
    }

    private function mapToResource(IndustryProjectBomLine $item): GroupIndustryBomResource
    {
        $resource = new GroupIndustryBomResource();
        $resource->id = $item->getId()->toString();
        $resource->typeId = $item->getTypeId();
        $resource->typeName = $item->getTypeName();
        $resource->requiredQuantity = $item->getRequiredQuantity();
        $resource->fulfilledQuantity = $item->getFulfilledQuantity();
        $resource->remainingQuantity = $item->getRemainingQuantity();
        $resource->fulfillmentPercent = $item->getFulfillmentPercent();
        $resource->estimatedPrice = $item->getEstimatedPrice();
        $resource->estimatedTotal = $item->getEstimatedPrice() !== null
            ? $item->getEstimatedPrice() * $item->getRequiredQuantity()
            : null;
        $resource->isJob = $item->isJob();
        $resource->jobGroup = $item->getJobGroup();
        $resource->activityType = $item->getActivityType();
        $resource->parentTypeId = $item->getParentTypeId();
        $resource->meLevel = $item->getMeLevel();
        $resource->teLevel = $item->getTeLevel();
        $resource->runs = $item->getRuns();
        $resource->isFulfilled = $item->isFulfilled();
        $resource->jobMatches = array_values($item->getJobMatches()->map(
            static fn (IndustryProjectJobMatch $match): array => get_object_vars(ProjectJobMatchResource::fromEntity($match)),
        )->toArray());
        $resource->matchedRuns = $item->getMatchedRuns();
        $resource->purchases = array_values($item->getPurchases()->map(
            static fn (IndustryProjectPurchase $purchase): array => get_object_vars(ProjectPurchaseResource::fromEntity($purchase)),
        )->toArray());
        $resource->purchasedQuantity = $item->getPurchasedQuantity();

        return $resource;
    }
}
