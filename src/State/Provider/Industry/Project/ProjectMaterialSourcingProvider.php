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
use App\Entity\IndustryProject;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\JobMaterialNeedsMapper;
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
        private IndustryProjectParticipantRepository $participantRepository,
        private IndustryProjectLineReservationRepository $reservationRepository,
        private IndustryProjectContributionRepository $contributionRepository,
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
        $reservedQuantityByLineId = $this->reservedQuantityByLineIdOf($project, $user);
        $structureId = $user->getEffectiveMarketStructureId();
        $sourcings = $this->comparator->compare(
            MaterialsToSourceFactory::reservedOf($bomLines, $planned->plan, $reservedQuantityByLineId, $this->containerFulfillment->ofWithJobMaterialNeeds($project, $bomLines, JobMaterialNeedsMapper::map($bomLines, $planned->plan))),
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
        $resource->structureName = null === $structureId ? null : $user->getEffectiveMarketStructureName();
        $resource->structureSynced = null !== $structureId && $this->structureMarketPrices->isSynced($structureId);
        $resource->structureLastSync = null === $structureId ? null : $this->structureMarketPrices->lastSyncOf($structureId)?->format('c');
        $resource->hasReservations = [] !== $reservedQuantityByLineId;
        $resource->totals = MaterialSourcingPresenter::totalsOf($sourcings);
        $resource->rows = array_map(
            static fn (MaterialSourcing $sourcing): array => MaterialSourcingPresenter::rowOf($sourcing, $typeNames[$sourcing->typeId]),
            $sourcings,
        );

        return $resource;
    }

    /**
     * @return array<string, int> quantity still to source by line id, for the material lines the reader reserved
     */
    private function reservedQuantityByLineIdOf(IndustryProject $project, User $user): array
    {
        $participant = $this->participantRepository->findOneBy([
            'project' => $project,
            'user' => $user,
            'status' => ParticipantStatus::Accepted,
        ]);
        if (null === $participant) {
            return [];
        }

        $pendingQuantities = $this->contributionRepository->sumPendingQuantityByLineAndParticipant($project);
        $participantId = $participant->getId()->toRfc4122();
        $reservedQuantityByLineId = [];
        foreach ($this->reservationRepository->findByProjectAndParticipant($project, $participant) as $reservation) {
            $line = $reservation->getBomLine();
            if ($line->isJob()) {
                continue;
            }
            $lineId = $line->getId()->toRfc4122();
            $reservedQuantityByLineId[$lineId] = max(0, $reservation->getQuantity() - ($pendingQuantities[$lineId][$participantId] ?? 0));
        }

        return $reservedQuantityByLineId;
    }
}
