<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\AvailableJobResource;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\IndustryCalculationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

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

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        // Collect all blueprint type IDs used in the project
        $blueprintTypeIds = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() !== 'copy') {
                $blueprintTypeIds[$step->getBlueprintTypeId()] = true;
            }
        }

        if (empty($blueprintTypeIds)) {
            return [];
        }

        // Collect character IDs
        $characterIds = [];
        foreach ($user->getCharacters() as $character) {
            $characterIds[] = $character->getId();
        }

        if (empty($characterIds)) {
            return [];
        }

        $projectStartDate = $project->getEffectiveJobsStartDate();

        // Query all ESI jobs matching these blueprints
        $jobs = $this->jobRepository->findByBlueprintsAndCharacters(
            array_keys($blueprintTypeIds),
            $characterIds,
            $projectStartDate,
        );

        // Build a map of esiJobId → match info from ALL project steps
        $linkedJobs = [];
        foreach ($project->getSteps() as $step) {
            foreach ($step->getJobMatches() as $match) {
                $linkedJobs[$match->getEsiJobId()] = [
                    'stepId' => (string) $step->getId(),
                    'stepName' => $this->calculationService->resolveTypeName($step->getProductTypeId()),
                    'matchId' => (string) $match->getId(),
                ];
            }
        }

        // Build resources
        $resources = [];
        foreach ($jobs as $job) {
            $resource = new AvailableJobResource();
            $resource->esiJobId = $job->getJobId();
            $resource->blueprintTypeId = $job->getBlueprintTypeId();
            $resource->productTypeId = $job->getProductTypeId();
            $resource->productTypeName = $this->calculationService->resolveTypeName($job->getProductTypeId());
            $resource->runs = $job->getRuns();
            $resource->cost = $job->getCost();
            $resource->status = $job->getStatus();
            $resource->startDate = $job->getStartDate()->format('c');
            $resource->endDate = $job->getEndDate()->format('c');
            $resource->characterName = $job->getCharacter()->getName();

            if (isset($linkedJobs[$job->getJobId()])) {
                $resource->linkedToStepId = $linkedJobs[$job->getJobId()]['stepId'];
                $resource->linkedToStepName = $linkedJobs[$job->getJobId()]['stepName'];
                $resource->matchId = $linkedJobs[$job->getJobId()]['matchId'];
            }

            $resources[] = $resource;
        }

        // Sort by date DESC
        usort($resources, fn (AvailableJobResource $a, AvailableJobResource $b) => strcmp($b->startDate, $a->startDate));

        return $resources;
    }
}
