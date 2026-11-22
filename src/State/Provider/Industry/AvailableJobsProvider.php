<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\AvailableJobResource;
use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Exception\InventionDataMissingException;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<AvailableJobResource>
 */
class AvailableJobsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly IndustryCalculationService $calculationService,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly IndustryProjectJobMatchRepository $jobMatchRepository,
        private readonly IndustryJobMatcher $jobMatcher,
    ) {
    }

    /**
     * @return AvailableJobResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'], 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $jobOwners = $this->accessChecker->usersWhoseJobsCanBeLinkedBy($user, $project);

        $jobLines = $project->getBomItems()->filter(
            static fn (IndustryProjectBomLine $line): bool => $line->isMatchableJob(),
        );

        $blueprintTypeIds = [];
        $jobLinesByBlueprintTypeId = [];
        $activityIds = [];
        foreach ($jobLines as $line) {
            try {
                $blueprintTypeId = $this->jobMatcher->expectedBlueprintTypeIdOf($line);
            } catch (InventionDataMissingException) {
                continue;
            }

            $blueprintTypeIds[$blueprintTypeId] = true;
            $jobLinesByBlueprintTypeId[$blueprintTypeId][] = $line;
            $activityIds = [...$activityIds, ...$line->esiJobActivityIds()];
        }

        $characterIds = [];
        foreach ($jobOwners as $jobOwner) {
            foreach ($jobOwner->getCharacters() as $character) {
                $characterIds[] = $character->getId();
            }
        }

        if ($blueprintTypeIds === [] || $characterIds === []) {
            return [];
        }

        $jobs = $this->jobRepository->findByBlueprintsAndCharacters(
            array_keys($blueprintTypeIds),
            array_values(array_unique($activityIds)),
            $characterIds,
            $project->getCreatedAt(),
        );

        $jobsWithOutput = array_filter($jobs, static fn (CachedIndustryJob $job): bool => !$job->hasNoOutput());

        $linkedRunsByJobId = array_fill_keys(array_map(static fn (CachedIndustryJob $job): int => $job->getJobId(), $jobsWithOutput), 0);
        foreach ($this->jobMatchRepository->findByEsiJobIds(array_map(static fn (CachedIndustryJob $job): int => $job->getJobId(), $jobsWithOutput)) as $match) {
            $linkedRunsByJobId[$match->getEsiJobId()] += $match->getRuns();
        }

        $resources = array_map(
            fn (CachedIndustryJob $job): AvailableJobResource => $this->toResource(
                $job,
                $project,
                $linkedRunsByJobId[$job->getJobId()],
                $jobLinesByBlueprintTypeId[$job->getBlueprintTypeId()],
            ),
            $jobsWithOutput,
        );

        usort($resources, static fn (AvailableJobResource $a, AvailableJobResource $b): int => strcmp($b->startDate, $a->startDate));

        return $resources;
    }

    /**
     * @param list<IndustryProjectBomLine> $linesOfTheBlueprint
     */
    private function toResource(CachedIndustryJob $job, IndustryProject $project, int $linkedRuns, array $linesOfTheBlueprint): AvailableJobResource
    {
        $resource = new AvailableJobResource();
        $resource->esiJobId = $job->getJobId();
        $resource->blueprintTypeId = $job->getBlueprintTypeId();
        $resource->productTypeId = $job->getDisplayedTypeId();
        $resource->productTypeName = $this->calculationService->resolveTypeName($job->getDisplayedTypeId());
        $resource->runs = $job->getRuns();
        $resource->cost = $job->getCost();
        $resource->status = $job->getStatus();
        $resource->startDate = $job->getStartDate()->format('c');
        $resource->endDate = $job->getEndDate()->format('c');
        $resource->characterName = $job->getCharacter()->getName();
        $resource->linkedRuns = $linkedRuns;
        $resource->remainingRuns = $job->getRuns() - $linkedRuns;
        $resource->linkableBomLineIds = array_values(array_map(
            static fn (IndustryProjectBomLine $line): string => $line->getId()->toRfc4122(),
            array_filter($linesOfTheBlueprint, fn (IndustryProjectBomLine $line): bool => $this->jobMatcher->isJobOfBomLineActivity($job, $line)),
        ));

        $linkedRunsInThisProject = 0;
        foreach ($project->getBomItems() as $line) {
            foreach ($line->getJobMatches() as $match) {
                if ($match->getEsiJobId() === $job->getJobId()) {
                    $linkedRunsInThisProject += $match->getRuns();
                    $resource->linkedToBomLineId = $line->getId()->toRfc4122();
                    $resource->linkedToBomLineName = $line->getTypeName();
                    $resource->matchId = (string) $match->getId();
                }
            }
        }

        $resource->linkedToAnotherProject = $linkedRuns > $linkedRunsInThisProject;

        return $resource;
    }
}
