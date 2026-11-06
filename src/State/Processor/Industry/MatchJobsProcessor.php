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
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Sync\CharacterSyncFailure;
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
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MatchJobsResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'], 'Project not found'));

        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertOwner($user, $project);

        $syncedCount = 0;
        $failures = [];

        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() === null) {
                $failures[] = CharacterSyncFailure::missingToken($character);
                continue;
            }

            try {
                $this->jobSyncService->syncCharacterJobs($character);
                $syncedCount++;
            } catch (\Throwable $e) {
                $failure = CharacterSyncFailure::fromThrowable($character, $e);
                $this->logger->warning('Failed to sync jobs for character', $failure->logContext($e->getMessage()));
                $failures[] = $failure;
            }
        }

        $this->jobMatcher->matchEsiJobs($project);

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

        return $result;
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
