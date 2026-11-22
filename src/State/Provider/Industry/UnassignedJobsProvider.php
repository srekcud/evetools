<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\UnassignedJobResource;
use App\Entity\CachedIndustryJob;
use App\Entity\User;
use App\Repository\UnassignedJobRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\Project\AssignableCandidate;
use App\Service\Industry\Project\UnassignedJobCandidates;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<UnassignedJobResource>
 */
class UnassignedJobsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UnassignedJobRepository $unassignedJobRepository,
        private readonly UnassignedJobCandidates $candidates,
        private readonly IndustryCalculationService $calculationService,
    ) {
    }

    /**
     * @return list<UnassignedJobResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resources = [];
        foreach ($this->unassignedJobRepository->findOfUser($user) as $unassignedJob) {
            $job = $unassignedJob->getJob();
            if ($job->hasNoOutput()) {
                continue;
            }

            $candidates = $this->candidates->assignableTo($user, $unassignedJob);
            if ($candidates === []) {
                continue;
            }

            $resources[] = $this->toResource($job, $candidates);
        }

        usort($resources, static fn (UnassignedJobResource $a, UnassignedJobResource $b): int => strcmp($b->startDate, $a->startDate));

        return $resources;
    }

    /**
     * @param non-empty-list<AssignableCandidate> $candidates
     */
    private function toResource(CachedIndustryJob $job, array $candidates): UnassignedJobResource
    {
        $stationId = $job->getStationId();

        $resource = new UnassignedJobResource();
        $resource->esiJobId = $job->getJobId();
        $resource->characterName = $job->getCharacter()->getName();
        $resource->blueprintTypeId = $job->getBlueprintTypeId();
        $resource->productTypeId = $job->getDisplayedTypeId();
        $resource->productTypeName = $this->calculationService->resolveTypeName($job->getDisplayedTypeId());
        $resource->runs = $job->getRuns();
        $resource->activityId = $job->getActivityId();
        $resource->stationId = $stationId;
        $resource->stationName = $stationId === null ? null : $this->calculationService->resolveFacilityName($stationId);
        $resource->startDate = $job->getStartDate()->format('c');
        $resource->endDate = $job->getEndDate()->format('c');
        $resource->status = $job->getStatus();
        $resource->candidates = array_map(static fn (AssignableCandidate $candidate): array => [
            'projectId' => $candidate->project->getId()->toRfc4122(),
            'projectName' => $candidate->project->getName(),
            'lineId' => $candidate->line->getId()->toRfc4122(),
            'lineName' => $candidate->line->getTypeName(),
            'remainingRuns' => $candidate->remainingRuns,
            'score' => $candidate->score,
            'signals' => $candidate->signals,
            'oversized' => $candidate->oversized,
        ], $candidates);

        return $resource;
    }
}
