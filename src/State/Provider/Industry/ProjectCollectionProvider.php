<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProjectListResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
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
        private readonly IndustryResourceMapper $mapper,
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

        foreach ($projects as $project) {
            $projectResource = $this->mapper->projectToResource($project);
            $resource->projects[] = $projectResource;
            $totalProfit += $project->getProfit() ?? 0;
        }

        $resource->totalProfit = $totalProfit;

        return $resource;
    }
}
