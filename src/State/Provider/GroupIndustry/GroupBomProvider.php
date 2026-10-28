<?php

declare(strict_types=1);

namespace App\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GroupIndustry\GroupIndustryBomResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\User;
use App\Repository\GroupIndustryBomItemRepository;
use App\Repository\GroupIndustryProjectRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<GroupIndustryBomResource>
 */
class GroupBomProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryBomItemRepository $bomItemRepository,
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

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertCanRead($user, $project);

        $bomItems = $this->bomItemRepository->findBy(['project' => $project]);

        return array_map(
            fn (GroupIndustryBomItem $item) => $this->mapToResource($item),
            $bomItems,
        );
    }

    private function mapToResource(GroupIndustryBomItem $item): GroupIndustryBomResource
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

        return $resource;
    }
}
