<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\MatchJobsResultResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryProjectService;
use App\Service\Sync\IndustryJobSyncService;
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
        private readonly IndustryProjectService $projectService,
        private readonly IndustryJobSyncService $jobSyncService,
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

        $this->projectService->matchEsiJobs($project);

        $steps = [];
        foreach ($project->getSteps() as $step) {
            $steps[] = $this->toStepResource($this->projectService->serializeStep($step));
        }

        $result = new MatchJobsResultResource();
        $result->steps = $steps;
        $result->jobsCost = $project->getJobsCost();
        $result->syncedCharacters = $syncedCount;
        $result->warning = $warning;

        return $result;
    }

    private function toStepResource(array $step): ProjectStepResource
    {
        $resource = new ProjectStepResource();
        $resource->id = $step['id'];
        $resource->blueprintTypeId = $step['blueprintTypeId'];
        $resource->productTypeId = $step['productTypeId'];
        $resource->productTypeName = $step['productTypeName'];
        $resource->quantity = $step['quantity'];
        $resource->runs = $step['runs'];
        $resource->depth = $step['depth'];
        $resource->activityType = $step['activityType'];
        $resource->sortOrder = $step['sortOrder'];
        $resource->splitGroupId = $step['splitGroupId'] ?? null;
        $resource->splitIndex = $step['splitIndex'] ?? null;
        $resource->totalGroupRuns = $step['totalGroupRuns'] ?? null;
        $resource->purchased = $step['purchased'] ?? false;
        $resource->inStock = $step['inStock'] ?? false;
        $resource->esiJobsTotalRuns = $step['esiJobsTotalRuns'] ?? null;
        $resource->esiJobCost = $step['esiJobCost'] ?? null;
        $resource->esiJobStatus = $step['esiJobStatus'] ?? null;
        $resource->esiJobCharacterName = $step['esiJobCharacterName'] ?? null;
        $resource->esiJobsCount = $step['esiJobsCount'] ?? null;
        $resource->manualJobData = $step['manualJobData'] ?? false;

        return $resource;
    }
}
