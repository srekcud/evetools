<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectStructureHealthResource;
use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Industry\Application\JobStructureComparison;
use App\Industry\Application\PlanStructureComparator;
use App\Industry\Application\StructureHealthAssessor;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\PlannedStructureHealthReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectStructureHealthResource>
 */
final readonly class ProjectStructureHealthProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private PlanStructureComparator $comparator,
        private IndustryStructureConfigRepository $structureConfigRepository,
        private PlannedStructureHealthReader $healthReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectStructureHealthResource
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

        $comparisons = $this->comparator->compare(
            $planned,
            $this->projectService->structuresOf($owner),
            $this->projectService->favoriteSystemsOf($owner),
        );
        $plannedStructures = $this->plannedStructuresOf($owner, $comparisons);
        $health = $this->healthReader->read($user, $plannedStructures);

        $resource = new ProjectStructureHealthResource();
        $resource->id = $projectId;
        $resource->lowFuelThresholdDays = StructureHealthAssessor::LOW_FUEL_THRESHOLD_DAYS;
        $resource->rows = array_map(
            static function (IndustryStructureConfig $structure) use ($health): array {
                $structureId = $structure->getId()->toRfc4122();

                return [
                    'structureId' => $structureId,
                    'name' => $structure->getName(),
                    'kind' => $structure->getStructureType(),
                    'state' => $health[$structureId]->state->value,
                    'fuelDays' => $health[$structureId]->fuelDays,
                    'offlineServices' => $health[$structureId]->offlineServices,
                ];
            },
            $plannedStructures,
        );

        return $resource;
    }

    /**
     * @param list<JobStructureComparison> $comparisons
     *
     * @return list<IndustryStructureConfig>
     */
    private function plannedStructuresOf(User $owner, array $comparisons): array
    {
        $configurations = [];
        foreach ($this->structureConfigRepository->findByUser($owner) as $configuration) {
            $configurations[$configuration->getId()->toRfc4122()] = $configuration;
        }

        $planned = [];
        foreach ($comparisons as $comparison) {
            $configuration = null === $comparison->chosenStructureId ? null : ($configurations[$comparison->chosenStructureId] ?? null);
            if (null !== $configuration) {
                $planned[$configuration->getId()->toRfc4122()] = $configuration;
            }
        }

        return array_values($planned);
    }
}
