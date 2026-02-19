<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryTreeService;
use App\State\Provider\Industry\IndustryResourceMapper;
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
        private readonly IndustryProjectFactory $projectFactory,
        private readonly IndustryTreeService $treeService,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryResourceMapper $mapper,
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

        $this->projectFactory->regenerateSteps($project);

        $resource = $this->mapper->projectToResource($project);
        $resource->steps = array_map(
            fn ($step) => $this->mapper->stepToResource($step),
            $project->getSteps()->toArray()
        );

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
