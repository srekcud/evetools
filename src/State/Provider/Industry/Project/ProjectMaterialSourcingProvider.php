<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectMaterialSourcingResource;
use App\Entity\User;
use App\Industry\Application\JitaPurchase;
use App\Industry\Application\MaterialSourcing;
use App\Industry\Application\MaterialSourcingComparator;
use App\Industry\Application\StructureMarketPrices;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\MaterialSourcingPresenter;
use App\Service\Industry\Project\MaterialsToSourceFactory;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectMaterialSourcingResource>
 */
final readonly class ProjectMaterialSourcingProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private MaterialSourcingComparator $comparator,
        private StructureMarketPrices $structureMarketPrices,
        private ProjectContainerFulfillment $containerFulfillment,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectMaterialSourcingResource
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

        $bomLines = $this->bomLineRepository->findBy(['project' => $project]);
        $structureId = $user->getPreferredMarketStructureId();
        $sourcings = $this->comparator->compare(
            MaterialsToSourceFactory::of($bomLines, $planned->plan, $this->containerFulfillment->of($project)),
            $structureId,
            $this->projectService->favoriteSystemsOf($owner),
        );
        $typeNames = [];
        foreach ($bomLines as $line) {
            $typeNames[$line->getTypeId()] = $line->getTypeName();
        }

        $resource = new ProjectMaterialSourcingResource();
        $resource->id = $projectId;
        $resource->importIskPerCubicMeterJump = JitaPurchase::IMPORT_ISK_PER_CUBIC_METER_JUMP;
        $resource->structureId = $structureId;
        $resource->structureName = null === $structureId ? null : $user->getPreferredMarketStructureName();
        $resource->structureSynced = null !== $structureId && $this->structureMarketPrices->isSynced($structureId);
        $resource->structureLastSync = null === $structureId ? null : $this->structureMarketPrices->lastSyncOf($structureId)?->format('c');
        $resource->totals = MaterialSourcingPresenter::totalsOf($sourcings);
        $resource->rows = array_map(
            static fn (MaterialSourcing $sourcing): array => MaterialSourcingPresenter::rowOf($sourcing, $typeNames[$sourcing->typeId]),
            $sourcings,
        );

        return $resource;
    }
}
