<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectT2BlueprintsToProvideResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\InventedProductsReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectT2BlueprintsToProvideResource>
 */
final readonly class ProjectT2BlueprintsToProvideProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private InventedProductsReader $inventedProductsReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectT2BlueprintsToProvideResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $resource = new ProjectT2BlueprintsToProvideResource();
        $resource->id = $projectId;
        $resource->excludesInvention = $project->isExcludesInvention();
        if (!$project->isExcludesInvention()) {
            return $resource;
        }

        $lines = [];
        foreach ($this->inventedProductsReader->inventedProductsOf($project) as $productTypeId => $product) {
            $lines[] = ['productTypeId' => $productTypeId, 'typeName' => $product['typeName'], 'runs' => $product['manufacturingRuns']];
        }
        usort($lines, static fn (array $left, array $right): int => $left['typeName'] <=> $right['typeName']);
        $resource->lines = $lines;

        return $resource;
    }
}
