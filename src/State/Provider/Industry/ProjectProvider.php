<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryBlacklistService;
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
        private readonly IndustryResourceMapper $mapper,
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

        $resource = $this->mapper->projectToResource($project);
        $this->mapper->preloadSimilarJobs($project);
        $resource->steps = array_map(
            fn ($step) => $this->mapper->stepToResource($step),
            $project->getSteps()->toArray()
        );

        // Build production tree
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
        $resource->tree = $tree;

        return $resource;
    }
}
