<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\MatchJobsResultResource;
use App\ApiResource\Industry\ProjectJobMatchResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\LineMatchFailure;
use App\Service\Industry\Project\DerivedContributionService;
use App\Service\Industry\Project\ProjectJobActivityRecorder;
use App\Service\Sync\CharacterSyncFailure;
use App\Service\Sync\CharacterSyncIsolation;
use App\Service\Sync\IndustryJobSyncService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, MatchJobsResultResource>
 */
class MatchJobsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryJobMatcher $jobMatcher,
        private readonly IndustryJobSyncService $jobSyncService,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly LoggerInterface $logger,
        private readonly DerivedContributionService $derivedContributionService,
        private readonly ProjectJobActivityRecorder $jobActivityRecorder,
        private readonly CharacterRepository $characterRepository,
        private readonly CharacterSyncIsolation $syncIsolation,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MatchJobsResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = UuidOrNotFound::of($uriVariables['projectId'], 'Project not found');
        $project = $this->projectRepository->find($projectId);

        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $syncedCount = 0;
        $failures = [];
        $managerReset = false;

        foreach ($user->getCharacters()->toArray() as $listedCharacter) {
            $character = $this->characterRepository->findByEveCharacterId($listedCharacter->getEveCharacterId());

            if ($character === null) {
                $reloadFailure = new \LogicException('Character not found when reloaded for the job sync');
                $failure = CharacterSyncFailure::fromThrowable($listedCharacter, $reloadFailure);
                $this->logger->warning('Failed to sync jobs for character', $failure->logContext($reloadFailure->getMessage()));
                $failures[] = $failure;
                continue;
            }

            if ($character->getEveToken() === null) {
                $failures[] = CharacterSyncFailure::missingToken($character);
                continue;
            }

            try {
                $this->syncIsolation->run(fn () => $this->jobSyncService->syncCharacterJobs($character));
                $syncedCount++;
            } catch (\Throwable $e) {
                $managerReset = true;
                $failure = CharacterSyncFailure::fromThrowable($character, $e);
                $this->logger->warning('Failed to sync jobs for character', $failure->logContext($e->getMessage()));
                $failures[] = $failure;
            }
        }

        if ($managerReset) {
            $project = $this->projectRepository->find($projectId) ?? throw new NotFoundHttpException('Project not found');
        }

        $openProjects = array_values(array_filter(
            $this->projectRepository->findOpenOfAcceptedParticipant($user),
            static fn (IndustryProject $openProject): bool => $openProject->getStatus() !== ProjectStatus::Draft,
        ));
        if (!\in_array($project, $openProjects, true)) {
            $openProjects[] = $project;
        }
        $failuresByProjectId = $this->jobMatcher->matchOpenProjects($openProjects);
        $targetProjectId = $project->getId()->toRfc4122();
        $lineFailures = $failuresByProjectId[$targetProjectId];

        foreach ($openProjects as $matchedProject) {
            $this->jobActivityRecorder->recordJobsOf($matchedProject);
            $refreshFailures = $this->refreshContributionsOf($matchedProject);
            if ($matchedProject === $project) {
                $lineFailures = [...$lineFailures, ...$refreshFailures];
            }
        }

        $result = new MatchJobsResultResource();
        $jobMatches = $this->jobMatchesOf($project);
        $result->jobMatches = array_map(ProjectJobMatchResource::fromEntity(...), $jobMatches);
        $result->jobsCost = array_sum(array_filter(
            array_map(static fn (IndustryProjectJobMatch $match): ?float => $match->getCost(), $jobMatches),
            static fn (?float $cost): bool => $cost !== null,
        ));
        $result->syncedCharacters = $syncedCount;
        $result->failedCharacters = array_map(
            static fn (CharacterSyncFailure $failure): array => $failure->toArray(),
            $failures,
        );
        $result->failedLines = array_map(
            static fn (LineMatchFailure $failure): array => $failure->toArray(),
            $lineFailures,
        );

        return $result;
    }

    /**
     * @return list<LineMatchFailure>
     */
    private function refreshContributionsOf(IndustryProject $project): array
    {
        $failures = [];
        foreach ($project->getBomItems() as $line) {
            if (!$line->isManufacturingOrReactionJob()) {
                continue;
            }

            try {
                $this->derivedContributionService->refreshLine($line);
            } catch (\LogicException $e) {
                $failure = LineMatchFailure::contributionNotRefreshed($line);
                $this->logger->warning('Failed to match jobs of line', $failure->logContext($project->getId()->toRfc4122(), $e->getMessage()));
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @return IndustryProjectJobMatch[]
     */
    private function jobMatchesOf(IndustryProject $project): array
    {
        $jobMatches = [];
        foreach ($project->getBomItems() as $line) {
            foreach ($line->getJobMatches() as $match) {
                $jobMatches[] = $match;
            }
        }

        return $jobMatches;
    }
}
