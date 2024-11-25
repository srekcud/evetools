<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Project;
use App\Repository\ProjectRepository;
use App\Service\Builder\ProjectBuilder;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ProjectCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if(! $projects = $this->projectRepository->findAll())
        {
            throw new NotFoundHttpException("No projects found");
        }
        $page = $context['filters']['page'] ?? 1;


        return new TraversablePaginator(
            new ArrayCollection($projects),
            $page,
            Project::MAX_ITEMS_PER_PAGE,
            count($projects),
        );
    }
}
