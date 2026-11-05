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
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
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
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryCalculationService $calculationService,
        private readonly ProjectAccessChecker $accessChecker,
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

        $this->accessChecker->assertOwner($user, $project);

        $jobLines = $project->getBomItems()->filter(
            static fn (IndustryProjectBomLine $line): bool => $line->isManufacturingOrReactionJob(),
        );

        $blueprintTypeIds = [];
        foreach ($jobLines as $line) {
            $blueprintTypeIds[$this->productRepository->producerOf($line->getTypeId())->getTypeId()] = true;
        }

        $characterIds = [];
        foreach ($user->getCharacters() as $character) {
            $characterIds[] = $character->getId();
        }

        if ($blueprintTypeIds === [] || $characterIds === []) {
            return [];
        }

        $jobs = $this->jobRepository->findByBlueprintsAndCharacters(
            array_keys($blueprintTypeIds),
            $characterIds,
            $project->getCreatedAt(),
        );

        $resources = array_map(
            fn (CachedIndustryJob $job): AvailableJobResource => $this->toResource($job, $project),
            array_filter($jobs, static fn (CachedIndustryJob $job): bool => !$job->hasNoOutput()),
        );

        usort($resources, static fn (AvailableJobResource $a, AvailableJobResource $b): int => strcmp($b->startDate, $a->startDate));

        return $resources;
    }

    private function toResource(CachedIndustryJob $job, IndustryProject $project): AvailableJobResource
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

        foreach ($project->getBomItems() as $line) {
            foreach ($line->getJobMatches() as $match) {
                if ($match->getEsiJobId() === $job->getJobId()) {
                    $resource->linkedToBomLineId = $line->getId()->toRfc4122();
                    $resource->linkedToBomLineName = $line->getTypeName();
                    $resource->matchId = (string) $match->getId();
                }
            }
        }

        return $resource;
    }
}
