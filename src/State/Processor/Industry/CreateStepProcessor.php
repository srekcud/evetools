<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\SplitGroupMaterials;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<CreateStepInput, ProjectStepResource|array>
 */
class CreateStepProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryStepCalculator $stepCalculator,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return ProjectStepResource|array<string, mixed> */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource|array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['id'], 'Project not found'));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        assert($data instanceof CreateStepInput);

        $typeId = $data->typeId;
        $runs = $data->runs;
        $meLevel = $data->meLevel;
        $teLevel = $data->teLevel;
        $splitGroupId = $data->splitGroupId;
        $stepId = $data->stepId;

        if ($typeId === null && $splitGroupId === null && $stepId === null) {
            throw new BadRequestHttpException('typeId, splitGroupId or stepId is required');
        }

        if ($stepId !== null) {
            $referenceStep = $this->stepRepository->find(UuidOrNotFound::of($stepId, 'Step not found'));

            if ($referenceStep === null || $referenceStep->getProject() !== $project) {
                throw new NotFoundHttpException('Step not found');
            }

            $addedJob = $this->addJobToTheGroupOf($project, $referenceStep, $runs);

            return [
                'newStep' => $this->mapper->stepToResource($addedJob),
                'updatedStep' => $this->mapper->stepToResource($referenceStep),
            ];
        }

        if ($splitGroupId !== null) {
            $referenceStep = $this->firstJobOfTheGroup($project, $splitGroupId);

            if ($referenceStep === null) {
                throw new NotFoundHttpException('Split group not found');
            }

            return $this->mapper->stepToResource($this->addJobToTheGroupOf($project, $referenceStep, $runs));
        }

        // Create new step from typeId
        if ($typeId === null) {
            throw new BadRequestHttpException('typeId is required for new step');
        }

        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            throw new BadRequestHttpException('Unknown type');
        }

        try {
            $producer = SplitGroupMaterials::producerOf($this->activityProductRepository, $typeId);
        } catch (ProducerMissingFromSdeException $missingProducer) {
            throw new BadRequestHttpException($missingProducer->getMessage(), $missingProducer);
        }

        $activityType = match ($producer->getActivityId()) {
            IndustryActivityType::Reaction->value => 'reaction',
            default => 'manufacturing',
        };

        $maxSortOrder = 0;
        foreach ($project->getSteps() as $s) {
            if ($s->getSortOrder() > $maxSortOrder) {
                $maxSortOrder = $s->getSortOrder();
            }
        }

        $step = new IndustryProjectStep();
        $step->setBlueprintTypeId($producer->getTypeId());
        $step->setProductTypeId($typeId);
        $step->setQuantity($runs * $producer->getQuantity());
        $step->setRuns($runs);
        $step->setDepth(0);
        $step->setActivityType($activityType);
        $step->setSortOrder($maxSortOrder + 1);
        $step->setMeLevel($activityType === 'reaction' ? 0 : ($meLevel ?? 0));
        $step->setTeLevel($teLevel ?? 0);

        $project->addStep($step);
        $this->entityManager->flush();

        return $this->mapper->stepToResource($step);
    }

    private function firstJobOfTheGroup(IndustryProject $project, string $splitGroupId): ?IndustryProjectStep
    {
        $firstJob = null;
        foreach ($project->getSteps() as $step) {
            if ($step->getSplitGroupId() === $splitGroupId && ($firstJob === null || $step->getSplitIndex() < $firstJob->getSplitIndex())) {
                $firstJob = $step;
            }
        }

        return $firstJob;
    }

    private function addJobToTheGroupOf(IndustryProject $project, IndustryProjectStep $referenceStep, int $runs): IndustryProjectStep
    {
        try {
            $outputPerRun = SplitGroupMaterials::outputPerRunOf($this->activityProductRepository, $referenceStep->getBlueprintTypeId(), $referenceStep->getActivityType());
            $needRuns = $this->stepCalculator->needRunsOf($project, $referenceStep);

            $addedJob = $this->joinGroupOfTheReferenceStep($project, $referenceStep, $runs, $outputPerRun);
            $jobsOfTheGroup = $this->jobsOfTheGroup($project, $addedJob->getSplitGroupId());
            $totalGroupRuns = array_sum(array_map(static fn (IndustryProjectStep $job): int => $job->getRuns(), $jobsOfTheGroup));
            foreach ($jobsOfTheGroup as $job) {
                $job->setTotalGroupRuns($totalGroupRuns);
                if ($needRuns !== null) {
                    $job->setRunsFixed(true);
                }
            }

            $this->stepCalculator->recalculateStepQuantities($project);
        } catch (BlueprintProductMissingFromSdeException $missingProduct) {
            throw new BadRequestHttpException($missingProduct->getMessage(), $missingProduct);
        }

        $this->entityManager->flush();

        return $addedJob;
    }

    private function joinGroupOfTheReferenceStep(IndustryProject $project, IndustryProjectStep $referenceStep, int $runs, int $outputPerRun): IndustryProjectStep
    {
        if ($referenceStep->getSplitGroupId() === null) {
            $referenceStep->setSplitGroupId(Uuid::v4()->toRfc4122());
            $referenceStep->setSplitIndex(0);
        }

        $splitGroupId = $referenceStep->getSplitGroupId();
        $lastSplitIndex = max($referenceStep->getSplitIndex(), ...array_map(static fn (IndustryProjectStep $job): int => $job->getSplitIndex(), $this->jobsOfTheGroup($project, $splitGroupId)));

        $addedJob = new IndustryProjectStep();
        $addedJob->setBlueprintTypeId($referenceStep->getBlueprintTypeId());
        $addedJob->setProductTypeId($referenceStep->getProductTypeId());
        $addedJob->setQuantity($runs * $outputPerRun);
        $addedJob->setRuns($runs);
        $addedJob->setDepth($referenceStep->getDepth());
        $addedJob->setActivityType($referenceStep->getActivityType());
        $addedJob->setSortOrder($referenceStep->getSortOrder());
        $addedJob->setSplitGroupId($splitGroupId);
        $addedJob->setSplitIndex($lastSplitIndex + 1);
        $addedJob->setMeLevel($referenceStep->getActivityType() === 'reaction' ? 0 : $referenceStep->getMeLevel());
        $addedJob->setTeLevel($referenceStep->getTeLevel());
        $addedJob->setStructureConfig($referenceStep->getStructureConfig());

        $project->addStep($addedJob);

        return $addedJob;
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private function jobsOfTheGroup(IndustryProject $project, ?string $splitGroupId): array
    {
        return array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $job): bool => $job->getSplitGroupId() === $splitGroupId,
        ));
    }
}
