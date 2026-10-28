<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\SplitGroupMaterials;
use App\Service\Industry\SplitGroupQuantities;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateStepInput, ProjectStepResource|array>
 */
class UpdateStepProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryStepCalculator $stepCalculator,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return ProjectStepResource|list<ProjectStepResource> */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource|array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        $step = $this->stepRepository->find(Uuid::fromString($uriVariables['stepId']));

        if ($step === null || $step->getProject() !== $project) {
            throw new NotFoundHttpException('Step not found');
        }

        assert($data instanceof UpdateStepInput);

        try {
            return $this->update($project, $step, $data);
        } catch (BlueprintProductMissingFromSdeException $missingProduct) {
            throw new BadRequestHttpException($missingProduct->getMessage(), $missingProduct);
        }
    }

    /** @return ProjectStepResource|list<ProjectStepResource> */
    private function update(IndustryProject $project, IndustryProjectStep $step, UpdateStepInput $data): ProjectStepResource|array
    {
        $needsCascade = false;

        if ($data->purchased !== null) {
            if ($step->getDepth() === 0 && $data->purchased) {
                throw new BadRequestHttpException('Cannot mark root products as purchased');
            }
            $step->setPurchased($data->purchased);
        }

        if ($data->inStockQuantity !== null) {
            if ($step->getDepth() === 0 && $data->inStockQuantity > 0) {
                throw new BadRequestHttpException('Cannot mark root products as in stock');
            }
            $step->setInStockQuantity($data->inStockQuantity);
        }

        if ($data->meLevel !== null && $data->meLevel !== $step->getMeLevel()) {
            $step->setMeLevel($data->meLevel);
            $needsCascade = true;
        }

        if ($data->teLevel !== null) {
            $step->setTeLevel($data->teLevel);
        }

        if ($data->structureConfigId !== null) {
            $structureConfig = $this->structureConfigRepository->find(Uuid::fromString($data->structureConfigId));
            if ($structureConfig !== $step->getStructureConfig()) {
                $step->setStructureConfig($structureConfig);
                $needsCascade = true;
            }
        }

        if ($data->jobMatchMode !== null) {
            $step->setJobMatchMode($data->jobMatchMode);
        }

        if ($data->runs !== null && $data->runs >= 1) {
            $quantityPerRun = SplitGroupMaterials::outputPerRunOf($this->activityProductRepository, $step->getBlueprintTypeId(), $step->getActivityType());
            $needRuns = $this->stepCalculator->needRunsOf($project, $step);
            $runs = $needRuns === null ? $data->runs : $this->fixRunsEnteredOnTheJob($project, $step, $data->runs, $needRuns, $data->overproduce === true);
            $step->setRuns($runs);
            $step->setQuantity($runs * $quantityPerRun);
            $needsCascade = true;
        }

        if ($data->runsFixed === false) {
            $this->releaseFixedRunsOfTheGroup($project, $step);
            $needsCascade = true;
        }

        $cascadedSteps = $needsCascade ? $this->stepCalculator->recalculateStepQuantities($project) : [];

        $this->entityManager->flush();

        if (!empty($cascadedSteps)) {
            $updatedStepResources = [$this->mapper->stepToResource($step)];
            foreach ($cascadedSteps as $updatedStep) {
                if ($updatedStep !== $step) {
                    $updatedStepResources[] = $this->mapper->stepToResource($updatedStep);
                }
            }
            return $updatedStepResources;
        }

        return $this->mapper->stepToResource($step);
    }

    private function fixRunsEnteredOnTheJob(IndustryProject $project, IndustryProjectStep $job, int $enteredRuns, int $needRuns, bool $overproduce): int
    {
        $otherJobs = array_filter($this->jobsOfTheGroupOf($project, $job), static fn (IndustryProjectStep $otherJob): bool => $otherJob !== $job);
        $job->setRunsFixed(true);

        if ($overproduce) {
            foreach ($otherJobs as $otherJob) {
                $otherJob->setRunsFixed(true);
            }

            return $enteredRuns;
        }

        $runsKeptForTheOtherJobs = array_sum(array_map(
            static fn (IndustryProjectStep $otherJob): int => $otherJob->isRunsFixed() ? $otherJob->getRuns() : 1,
            $otherJobs,
        ));

        return max(1, min($enteredRuns, $needRuns - $runsKeptForTheOtherJobs));
    }

    private function releaseFixedRunsOfTheGroup(IndustryProject $project, IndustryProjectStep $step): void
    {
        $jobs = $this->jobsOfTheGroupOf($project, $step);
        foreach ($jobs as $job) {
            $job->setRunsFixed(false);
        }

        $needRuns = $this->stepCalculator->needRunsOf($project, $step);
        if ($needRuns === null) {
            return;
        }

        foreach (SplitGroupQuantities::runsBySplitIndex($needRuns, \count($jobs)) as $splitIndex => $runs) {
            $jobs[$splitIndex]->setRuns($runs);
        }
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private function jobsOfTheGroupOf(IndustryProject $project, IndustryProjectStep $step): array
    {
        $splitGroupId = $step->getSplitGroupId();
        if ($splitGroupId === null) {
            return [$step];
        }

        $jobs = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $job): bool => $job->getSplitGroupId() === $splitGroupId,
        ));
        usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $jobs;
    }
}
