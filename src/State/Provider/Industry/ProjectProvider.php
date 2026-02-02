<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryProjectService;
use App\Service\Industry\IndustryTreeService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<ProjectResource>
 */
class ProjectProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $summary = $this->projectService->getProjectSummary($project);

        $steps = [];
        foreach ($project->getSteps() as $step) {
            $steps[] = $this->toStepResource($this->projectService->serializeStep($step));
        }

        $excludedTypeIds = $this->blacklistService->resolveBlacklistedTypeIds($user);
        try {
            $tree = $this->treeService->buildProductionTree(
                $project->getProductTypeId(),
                $project->getRuns(),
                $project->getMeLevel(),
                $excludedTypeIds,
                $user,
            );
        } catch (\RuntimeException) {
            $tree = null;
        }

        $resource = $this->toResource($summary);
        $resource->steps = $steps;
        $resource->tree = $tree;

        return $resource;
    }

    private function toResource(array $summary): ProjectResource
    {
        $resource = new ProjectResource();
        $resource->id = $summary['id'];
        $resource->productTypeId = $summary['productTypeId'];
        $resource->productTypeName = $summary['productTypeName'];
        $resource->name = $summary['name'] ?? null;
        $resource->displayName = $summary['displayName'] ?? $summary['productTypeName'];
        $resource->runs = $summary['runs'];
        $resource->meLevel = $summary['meLevel'];
        $resource->teLevel = $summary['teLevel'] ?? 0;
        $resource->maxJobDurationDays = $summary['maxJobDurationDays'];
        $resource->status = $summary['status'];
        $resource->profit = $summary['profit'] ?? null;
        $resource->profitPercent = $summary['profitPercent'] ?? null;
        $resource->bpoCost = $summary['bpoCost'] ?? null;
        $resource->materialCost = $summary['materialCost'] ?? null;
        $resource->transportCost = $summary['transportCost'] ?? null;
        $resource->taxAmount = $summary['taxAmount'] ?? null;
        $resource->sellPrice = $summary['sellPrice'] ?? null;
        $resource->jobsCost = $summary['jobsCost'] ?? null;
        $resource->totalCost = $summary['totalCost'] ?? null;
        $resource->notes = $summary['notes'] ?? null;
        $resource->personalUse = $summary['personalUse'] ?? false;
        $resource->jobsStartDate = $summary['jobsStartDate'] ?? null;
        $resource->completedAt = $summary['completedAt'] ?? null;
        $resource->createdAt = $summary['createdAt'];
        $resource->rootProducts = $summary['rootProducts'] ?? [];

        return $resource;
    }

    private function toStepResource(array $step): ProjectStepResource
    {
        $resource = new ProjectStepResource();
        $resource->id = $step['id'];
        $resource->blueprintTypeId = $step['blueprintTypeId'];
        $resource->productTypeId = $step['productTypeId'];
        $resource->productTypeName = $step['productTypeName'];
        $resource->quantity = $step['quantity'];
        $resource->runs = $step['runs'];
        $resource->depth = $step['depth'];
        $resource->activityType = $step['activityType'];
        $resource->sortOrder = $step['sortOrder'];
        $resource->splitGroupId = $step['splitGroupId'] ?? null;
        $resource->splitIndex = $step['splitIndex'] ?? null;
        $resource->totalGroupRuns = $step['totalGroupRuns'] ?? null;
        $resource->purchased = $step['purchased'] ?? false;
        $resource->inStock = $step['inStock'] ?? false;
        $resource->inStockQuantity = $step['inStockQuantity'] ?? 0;
        $resource->meLevel = $step['meLevel'] ?? null;
        $resource->teLevel = $step['teLevel'] ?? null;
        $resource->recommendedStructureName = $step['recommendedStructureName'] ?? null;
        $resource->structureBonus = $step['structureBonus'] ?? null;
        $resource->structureTimeBonus = $step['structureTimeBonus'] ?? null;
        $resource->timePerRun = $step['timePerRun'] ?? null;
        $resource->esiJobsTotalRuns = $step['esiJobsTotalRuns'] ?? null;
        $resource->esiJobCost = $step['esiJobCost'] ?? null;
        $resource->esiJobStatus = $step['esiJobStatus'] ?? null;
        $resource->esiJobCharacterName = $step['esiJobCharacterName'] ?? null;
        $resource->esiJobsCount = $step['esiJobsCount'] ?? null;
        $resource->manualJobData = $step['manualJobData'] ?? false;

        return $resource;
    }
}
