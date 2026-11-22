<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectContainerReadingResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContainerContent;
use App\Service\Industry\Project\ProjectContainerReading;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectContainerReadingResource>
 */
final readonly class ProjectContainerReadingsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectContainerReadings $readings,
    ) {
    }

    /**
     * @return list<ProjectContainerReadingResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        return array_map(
            fn (ProjectContainerReading $reading): ProjectContainerReadingResource => $this->resourceOf($reading),
            $this->readings->of($project),
        );
    }

    private function resourceOf(ProjectContainerReading $reading): ProjectContainerReadingResource
    {
        $resource = new ProjectContainerReadingResource();
        $resource->containerId = $reading->containerId;
        $resource->containerName = $reading->containerName;
        $resource->locationId = $reading->locationId;
        $resource->status = $reading->status;
        $resource->unknownReason = $reading->unknownReason;
        $resource->items = null === $reading->contents
            ? null
            : array_map(
                static fn (ProjectContainerContent $content): array => ['typeId' => $content->typeId, 'typeName' => $content->typeName, 'quantity' => $content->quantity],
                $reading->contents,
            );
        $resource->assetsSyncedAt = $reading->assetsSyncedAt;

        return $resource;
    }
}
