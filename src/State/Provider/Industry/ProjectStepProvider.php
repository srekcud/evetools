<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<ProjectStepResource>
 */
class ProjectStepProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $step = $this->stepRepository->find(Uuid::fromString($uriVariables['stepId']));

        if ($step === null || $step->getProject() !== $project) {
            throw new NotFoundHttpException('Step not found');
        }

        $resource = new ProjectStepResource();
        $resource->id = $step->getId()->toRfc4122();
        $resource->blueprintTypeId = $step->getBlueprintTypeId();
        $resource->productTypeId = $step->getProductTypeId();
        $resource->productTypeName = $step->getProductTypeName();
        $resource->quantity = $step->getQuantity();
        $resource->runs = $step->getRuns();
        $resource->depth = $step->getDepth();
        $resource->activityType = $step->getActivityType();
        $resource->sortOrder = $step->getSortOrder();
        $resource->splitGroupId = $step->getSplitGroupId();
        $resource->splitIndex = $step->getSplitIndex();
        $resource->totalGroupRuns = $step->getTotalGroupRuns();
        $resource->purchased = $step->isPurchased();
        $resource->inStock = $step->isInStock();
        $resource->inStockQuantity = $step->getInStockQuantity();
        $resource->meLevel = $step->getMeLevel();
        $resource->teLevel = $step->getTeLevel();
        $resource->recommendedStructureName = $step->getRecommendedStructureName();
        $resource->structureBonus = $step->getStructureBonus();
        $resource->structureTimeBonus = $step->getStructureTimeBonus();
        $resource->timePerRun = $step->getTimePerRun();
        $resource->esiJobsTotalRuns = $step->getEsiJobsTotalRuns();
        $resource->esiJobCost = $step->getEsiJobCost();
        $resource->esiJobStatus = $step->getEsiJobStatus();
        $resource->esiJobCharacterName = $step->getEsiJobCharacterName();
        $resource->esiJobsCount = $step->getEsiJobsCount();
        $resource->manualJobData = $step->isManualJobData();

        return $resource;
    }
}
