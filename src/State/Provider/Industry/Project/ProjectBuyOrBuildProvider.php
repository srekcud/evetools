<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectBuyOrBuildResource;
use App\Entity\User;
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

        $comparisons = $this->comparator->compare(
            $planned->plan,
            $this->projectService->structuresOf($owner),
            $this->projectService->favoriteSystemsOf($owner),
        );
        $typeNames = $this->typeNameResolver->findNames(array_map(
            static fn (IntermediateComparison $comparison): int => $comparison->typeId,
            $comparisons,
        ));

        $resource = new ProjectBuyOrBuildResource();
        $resource->id = $projectId;
        $resource->importIskPerCubicMeterJump = IntermediateBuyOrBuildComparator::IMPORT_ISK_PER_CUBIC_METER_JUMP;
        $resource->rows = array_map(
            static fn (IntermediateComparison $comparison): array => BuyOrBuildRowPresenter::of($comparison, $typeNames[$comparison->typeId] ?? null),
            $comparisons,
        );

        return $resource;
    }
}
