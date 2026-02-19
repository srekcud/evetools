<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\CostEstimationResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\ProductionCostService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<CostEstimationResource>
 */
class CostEstimationProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProductionCostService $productionCostService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CostEstimationResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $estimation = $this->productionCostService->estimateTotalCost($project);

        $resource = new CostEstimationResource();
        $resource->id = $uriVariables['id'];
        $resource->materialCost = $estimation['materialCost'];
        $resource->jobInstallCost = $estimation['jobInstallCost'];
        $resource->bpoCost = $estimation['bpoCost'];
        $resource->totalCost = $estimation['totalCost'];
        $resource->perUnit = $estimation['perUnit'];
        $resource->materials = $estimation['materials'];
        $resource->jobInstallSteps = $estimation['jobInstallSteps'];

        return $resource;
    }
}
