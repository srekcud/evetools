<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
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
 * @implements ProcessorInterface<mixed, ProjectResource>
 */
class RegenerateStepsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->projectService->regenerateSteps($project);

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
        $resource->runs = $summary['runs'];
        $resource->meLevel = $summary['meLevel'];
        $resource->teLevel = $summary['teLevel'] ?? 0;
        $resource->maxJobDurationDays = $summary['maxJobDurationDays'];
        $resource->status = $summary['status'];
        $resource->profit = $summary['profit'] ?? null;
        $resource->createdAt = $summary['createdAt'];

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

        return $resource;
    }
}
