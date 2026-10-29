<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\ProductionTreeFromSteps;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectResource>
 */
class ProjectProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly ProductionTreeFromSteps $productionTree,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Project not found'));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $resource = $this->mapper->projectToResource($project);
        $this->mapper->preloadSimilarJobs($project);
        $resource->steps = array_map(
            fn ($step) => $this->mapper->stepToResource($step),
            $project->getSteps()->toArray()
        );

        $productionTree = $this->productionTree->buildTree($project);
        $resource->tree = $productionTree['tree'];
        $resource->failedProducts = $productionTree['failedProducts'];

        return $resource;
    }
}
