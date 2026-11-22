<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectChainResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ChainBlueprintNames;
use App\Service\Industry\Project\JobInstallerName;
use App\Service\Industry\Project\ProjectChainLoader;
use App\Service\Industry\Project\ProjectChainNode;
use App\Service\Industry\Project\ProjectJobLocationResolver;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectChainResource>
 */
final readonly class ProjectChainProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectChainLoader $chainLoader,
        private ChainBlueprintNames $blueprintNames,
        private ProjectJobLocationResolver $jobLocationResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectChainResource
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

        $bomLines = $this->bomLineRepository->findBy(['project' => $project]);
        $this->bomLineRepository->loadJobMatchesOfProject($project);

        $chain = $this->chainLoader->load($project, $bomLines);

        $blueprintNameByNodeId = $this->blueprintNames->of($chain->nodes);

        $locationsByJobId = $this->accessChecker->isAdminOrOwner($user, $project)
            ? $this->jobLocationResolver->locationsOf($project, $this->jobMatchesOf($bomLines))
            : [];

        $resource = new ProjectChainResource();
        $resource->id = $projectId;
        $resource->nodes = array_map(
            static fn (ProjectChainNode $node): ProjectChainNode => $node->withJobs(array_map(
                static fn (array $job): array => [...$job, 'characterName' => JobInstallerName::seenBy($job['characterName'], $project, $user), 'locations' => $locationsByJobId[$job['esiJobId']] ?? null],
                $node->jobs,
            ))->withPlannedCopyDurationSeenBy($project->getOwner(), $user)->withBlueprintName($blueprintNameByNodeId[$node->id] ?? null),
            $chain->nodes,
        );

        return $resource;
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     *
     * @return list<IndustryProjectJobMatch>
     */
    private function jobMatchesOf(array $bomLines): array
    {
        $matches = [];
        foreach ($bomLines as $bomLine) {
            foreach ($bomLine->getJobMatches() as $match) {
                $matches[] = $match;
            }
        }

        return $matches;
    }
}
