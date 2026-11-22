<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectSourcingDecisionsResource;
use App\Entity\User;
use App\Industry\Application\DeliveredSourcingLine;
use App\Industry\Application\DeliveredSourcingPlanner;
use App\Industry\Application\PlannedProduction;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\DeliveredSourcingPresenter;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ProjectSourcingChoices;
use App\Service\TypeNameResolver;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectSourcingDecisionsResource>
 */
final readonly class ProjectSourcingDecisionsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private ProjectSourcingChoices $choices,
        private DeliveredSourcingPlanner $planner,
        private CachedStructureRepository $cachedStructureRepository,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectSourcingDecisionsResource
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
        $request = $context['request'] ?? null;
        $buys = $this->choices->buysOf($project, $request?->query->getString('buy') ?? '', $request?->query->getString('build') ?? '');

        $marketStructureId = $user->getEffectiveMarketStructureId();
        try {
            $universe = $this->projectService->planWithPurchasesOf($project, $owner, []);
            $sourcing = $this->planner->plan(
                $universe,
                fn (array $boughtTypeIds): PlannedProduction => $this->projectService->planWithPurchasesOf($project, $owner, $boughtTypeIds),
                $this->projectService->favoriteSystemsOf($owner),
                $marketStructureId,
                null === $marketStructureId ? null : $this->cachedStructureRepository->findByStructureId($marketStructureId)?->getSolarSystemId(),
                array_values(array_map(static fn ($item): int => $item->getTypeId(), $project->getItems()->toArray())),
                $buys,
                $this->choices->marketsOf($project),
            );
        } catch (\RuntimeException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        $typeNames = $this->typeNameResolver->findNames(array_map(static fn (DeliveredSourcingLine $line): int => $line->typeId, $sourcing->lines));

        $resource = new ProjectSourcingDecisionsResource();
        $resource->id = $projectId;
        $resource->rows = array_map(
            static fn (DeliveredSourcingLine $line): array => DeliveredSourcingPresenter::rowOf($line, $typeNames[$line->typeId] ?? null),
            $sourcing->lines,
        );
        $resource->totals = DeliveredSourcingPresenter::totalsOf($sourcing);
        $resource->boughtTypeIds = $sourcing->boughtTypeIds;

        return $resource;
    }
}
