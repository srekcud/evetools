<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectStructuresResource;
use App\Entity\User;
use App\Industry\Application\JobStructureComparison;
use App\Industry\Application\PlanStructureComparator;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureTotalCostComparator;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\StructureComparisonPresenter;
use App\Service\TypeNameResolver;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectStructuresResource>
 */
final readonly class ProjectStructuresProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private PlanStructureComparator $comparator,
        private IndustryStructureConfigRepository $structureConfigRepository,
        private MapSolarSystemRepository $solarSystemRepository,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectStructuresResource
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

        $structures = $this->projectService->structuresOf($owner);
        $comparisons = $this->comparator->compare($planned, $structures, $this->projectService->favoriteSystemsOf($owner));
        $typeNames = $this->typeNameResolver->findNames(array_map(
            static fn (JobStructureComparison $comparison): int => $comparison->productTypeId,
            $comparisons,
        ));
        $structureLabels = $this->structureLabelsOf($owner);
        $solarSystemNames = $this->solarSystemNamesOf($structures);

        $resource = new ProjectStructuresResource();
        $resource->id = $projectId;
        $resource->acceptsOutsideFavoriteSystem = $project->isAcceptsOutsideFavoriteSystem();
        $resource->minimumOutsideSaving = $project->getMinimumOutsideSaving();
        $resource->transportIskPerCubicMeterJump = StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP;
        $resource->groups = array_map(
            static fn (JobStructureComparison $comparison): array => StructureComparisonPresenter::of($comparison, $typeNames[$comparison->productTypeId] ?? null, $structureLabels, $solarSystemNames),
            $comparisons,
        );

        return $resource;
    }

    /**
     * @return array<string, array{name: string, kind: string}>
     */
    private function structureLabelsOf(User $owner): array
    {
        $labels = [];
        foreach ($this->structureConfigRepository->findByUser($owner) as $configuration) {
            $labels[$configuration->getId()->toRfc4122()] = ['name' => $configuration->getName(), 'kind' => $configuration->getStructureType()];
        }

        return $labels;
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return array<int, string>
     */
    private function solarSystemNamesOf(array $structures): array
    {
        $names = [];
        foreach ($structures as $structure) {
            $solarSystemId = $structure->solarSystemId;
            if (null !== $solarSystemId && !\array_key_exists($solarSystemId, $names)) {
                $solarSystem = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);
                if (null !== $solarSystem) {
                    $names[$solarSystemId] = $solarSystem->getSolarSystemName();
                }
            }
        }

        return $names;
    }
}
