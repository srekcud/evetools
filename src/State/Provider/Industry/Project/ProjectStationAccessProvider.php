<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectStationAccessResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\StructureAccess\ProjectStationAccess;
use App\Service\Industry\Project\StructureAccess\StationAccessCount;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectStationAccessResource>
 */
final readonly class ProjectStationAccessProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectStationAccess $stationAccess,
    ) {
    }

    /**
     * @return list<ProjectStationAccessResource>
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
        $this->accessChecker->assertAdminOrOwner($user, $project);

        return array_map($this->toResource(...), $this->stationAccess->of($project));
    }

    private function toResource(StationAccessCount $count): ProjectStationAccessResource
    {
        $resource = new ProjectStationAccessResource();
        $resource->locationId = $count->station->locationId ?? throw new \LogicException('A plan station always has a location.');
        $resource->name = $count->station->name;
        $resource->solarSystemId = $count->station->solarSystemId;
        $resource->canLaunch = $count->canLaunch;
        $resource->denied = $count->denied;
        $resource->unknown = $count->unknown;

        return $resource;
    }
}
