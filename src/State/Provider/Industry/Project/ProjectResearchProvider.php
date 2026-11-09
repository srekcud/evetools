<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectResearchResource;
use App\Entity\User;
use App\Industry\Application\ResearchProfitability;
use App\Industry\Application\ResearchSuggestion;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IndustryProjectService;
use App\Service\Industry\Project\ResearchRowPresenter;
use App\Service\TypeNameResolver;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ProjectResearchResource>
 */
final readonly class ProjectResearchProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IndustryProjectService $projectService,
        private ResearchProfitability $researchProfitability,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectResearchResource
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

        $suggestions = $this->researchProfitability->suggestionsOf(
            $planned->plan,
            $this->projectService->ownedBlueprintsOf($owner),
            $this->projectService->structuresOf($owner),
            $this->projectService->favoriteSystemsOf($owner),
        );
        $typeNames = $this->typeNameResolver->findNames(array_map(
            static fn (ResearchSuggestion $suggestion): int => $suggestion->productTypeId,
            $suggestions,
        ));

        $resource = new ProjectResearchResource();
        $resource->id = $projectId;
        $resource->rows = array_map(
            static fn (ResearchSuggestion $suggestion): array => ResearchRowPresenter::of($suggestion, $typeNames[$suggestion->productTypeId] ?? null),
            $suggestions,
        );

        return $resource;
    }
}
