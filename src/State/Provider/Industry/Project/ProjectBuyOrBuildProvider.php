<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectBuyOrBuildResource;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\IntermediateBuyOrBuildComparator;
use App\Industry\Application\IntermediateComparison;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\BuyOrBuildRowPresenter;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\TypeNameResolver;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectBuyOrBuildResource>
 */
final readonly class ProjectBuyOrBuildProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private IntermediateBuyOrBuildComparator $comparator,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectBuyOrBuildResource
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
        $favoriteSystems = $this->projectService->favoriteSystemsOf($owner);
        $comparisons = $this->comparator->compare($planned->plan, $structures, $favoriteSystems);
        $blacklistedComparisons = $this->blacklistedComparisonsOf($project, $owner, $structures, $favoriteSystems);
        $typeNames = $this->typeNameResolver->findNames(array_map(
            static fn (IntermediateComparison $comparison): int => $comparison->typeId,
            [...$comparisons, ...$blacklistedComparisons],
        ));

        $resource = new ProjectBuyOrBuildResource();
        $resource->id = $projectId;
        $resource->importIskPerCubicMeterJump = IntermediateBuyOrBuildComparator::IMPORT_ISK_PER_CUBIC_METER_JUMP;
        $resource->rows = [
            ...array_map(
                static fn (IntermediateComparison $comparison): array => [...BuyOrBuildRowPresenter::of($comparison, $typeNames[$comparison->typeId] ?? null), 'blacklisted' => false],
                $comparisons,
            ),
            ...array_map(
                static fn (IntermediateComparison $comparison): array => [...BuyOrBuildRowPresenter::of($comparison, $typeNames[$comparison->typeId] ?? null), 'blacklisted' => true],
                $blacklistedComparisons,
            ),
        ];

        return $resource;
    }

    /**
     * @param list<ProductionStructure> $structures
     *
     * @return list<IntermediateComparison>
     */
    private function blacklistedComparisonsOf(IndustryProject $project, User $owner, array $structures, FavoriteSystems $favoriteSystems): array
    {
        $blacklistedTypeIds = $project->getBlacklistTypeIds();
        if ([] === $blacklistedTypeIds) {
            return [];
        }

        $comparisons = [];
        foreach (array_unique($blacklistedTypeIds) as $typeId) {
            try {
                $plan = $this->projectService->planOf($project, $owner, $typeId)->plan;
            } catch (\RuntimeException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage(), $e);
            }

            if ([] === ($plan->intermediates[$typeId]->jobs ?? [])) {
                continue;
            }

            foreach ($this->comparator->compare($plan, $structures, $favoriteSystems) as $comparison) {
                if ($comparison->typeId === $typeId) {
                    $comparisons[] = $comparison;
                }
            }
        }

        return $comparisons;
    }
}
