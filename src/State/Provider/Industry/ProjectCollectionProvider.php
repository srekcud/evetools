<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectListResource;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryProjectService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectListResource>
 */
class ProjectCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectService $projectService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projects = $this->projectRepository->findByUser($user);

        $resource = new ProjectListResource();
        $totalProfit = 0.0;
        $projectData = [];

        foreach ($projects as $project) {
            $summary = $this->projectService->getProjectSummary($project);
            $projectData[] = $this->toResource($summary);
            $totalProfit += $summary['profit'] ?? 0;
        }

        $resource->projects = $projectData;
        $resource->totalProfit = $totalProfit;

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
}
