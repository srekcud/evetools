<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\MatchJobsResultResource;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Sync\IndustryJobSyncService;
use App\State\Provider\Industry\IndustryResourceMapper;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

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
        private readonly IndustryResourceMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MatchJobsResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->jobSyncService->resetCorporationTracking();
        $syncedCount = 0;
        $warning = null;

        foreach ($user->getCharacters() as $character) {
            try {
                $this->jobSyncService->syncCharacterJobs($character);
                $syncedCount++;
            } catch (EsiApiException $e) {
                if (in_array($e->statusCode, [502, 503, 504], true)) {
                    $warning = 'ESI est actuellement en maintenance. Les jobs seront synchronisés ultérieurement.';
                } else {
                    $this->logger->warning('Failed to sync jobs for character', [
                        'characterName' => $character->getName(),
                        'error' => $e->getMessage(),
                        'statusCode' => $e->statusCode,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync jobs for character', [
                    'characterName' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->jobMatcher->matchEsiJobs($project);

        $result = new MatchJobsResultResource();
        $result->steps = array_map(
            fn ($step) => $this->mapper->stepToResource($step),
            $project->getSteps()->toArray()
        );
        $result->jobsCost = $project->getJobsCost();
        $result->syncedCharacters = $syncedCount;
        $result->warning = $warning;

        return $result;
    }
}
