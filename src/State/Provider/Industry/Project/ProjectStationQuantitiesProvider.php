<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectStationQuantitiesResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectStationQuantities;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\TypeStationQuantities;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectStationQuantitiesResource>
 */
final readonly class ProjectStationQuantitiesProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectStationQuantities $stationQuantities,
    ) {
    }

    /**
     * @return list<ProjectStationQuantitiesResource>
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
            fn (TypeStationQuantities $quantities): ProjectStationQuantitiesResource => $this->resourceOf($quantities),
            $this->stationQuantities->of($project),
        );
    }

    private function resourceOf(TypeStationQuantities $quantities): ProjectStationQuantitiesResource
    {
        $resource = new ProjectStationQuantitiesResource();
        $resource->typeId = $quantities->typeId;
        $resource->typeName = $quantities->typeName;
        $resource->lineIds = $quantities->lineIds;
        $resource->unknownReason = $quantities->unknownReason;
        $resource->stations = null === $quantities->stations
            ? null
            : array_map(
                static fn (StationQuantity $station): array => [
                    'locationId' => $station->locationId,
                    'stationName' => $station->stationName,
                    'needed' => $station->needed,
                    'consumed' => $station->consumed,
                    'inProduction' => $station->inProduction,
                    'onSite' => $station->onSite,
                    'out' => $station->out,
                    'toBring' => $station->toBring,
                    'inTransit' => $station->inTransit,
                    'unknownReason' => $station->unknownReason,
                ],
                $quantities->stations,
            );

        return $resource;
    }
}
