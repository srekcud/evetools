<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectWorkStationsResource;
use App\Entity\User;
use App\Industry\Application\WorkTypeAdvice;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\OwnerStructureLabels;
use App\Service\Industry\Project\ProjectWorkStations;
use App\Service\Industry\Project\WorkStationPresenter;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectWorkStationsResource>
 */
final readonly class ProjectWorkStationsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private ProjectWorkStations $workStations,
        private OwnerStructureLabels $structureLabels,
        private MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectWorkStationsResource
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

        $owner = $project->getOwner();
        try {
            $planned = $this->projectService->planOf($project, $owner);
        } catch (\RuntimeException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        $advices = $this->workStations->adviceOf($project, $planned);
        $structureLabels = $this->structureLabels->of($owner);
        $solarSystemNames = $this->structureLabels->solarSystemNamesOf($this->projectService->structuresOf($owner));
        $storedWorkStations = $project->getWorkStations();
        $courierHub = $this->projectService->favoriteSystemsOf($owner)->courierHub
            ?? throw new \LogicException('The favorite systems of the owner carry no courier hub.');

        $resource = new ProjectWorkStationsResource();
        $resource->id = $projectId;
        $resource->courierHubSolarSystemId = $courierHub->solarSystemId;
        $resource->courierHubSolarSystemName = $this->solarSystemRepository->findBySolarSystemId($courierHub->solarSystemId)?->getSolarSystemName();
        $resource->courierIskPerCubicMeter = $courierHub->iskPerCubicMeterFromJita;
        $resource->jumpBridgeIskPerCubicMeter = $courierHub->jumpBridgeIskPerCubicMeter;
        $resource->structuresToSpecify = $this->structureLabels->hullToSpecifyOf($owner);
        $resource->workTypes = array_map(
            fn (WorkTypeAdvice $advice): array => WorkStationPresenter::of(
                $advice,
                $this->workStations->lockOf($project, $advice->workType, $planned),
                $storedWorkStations[$advice->workType->value]['recommendedStructureId'] ?? null,
                $structureLabels,
                $solarSystemNames,
            ),
            $advices,
        );

        return $resource;
    }
}
