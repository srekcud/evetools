<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\SplitStepInput;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\SplitGroupMaterialNeeds;
use App\Service\Industry\SplitGroupQuantities;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<SplitStepInput, array>
 */
class SplitStepProcessor implements ProcessorInterface
{
    private const int OUTPUT_PER_COPY_RUN = 1;

    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        private readonly SplitGroupMaterialNeeds $splitGroupMaterialNeeds,
    ) {
    }

    /**
     * @return ProjectStepResource[]
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): array
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

        assert($data instanceof SplitStepInput);

        $numberOfJobs = $data->numberOfJobs;
        $totalRuns = $step->getRuns();

        if ($totalRuns < $numberOfJobs) {
            throw new BadRequestHttpException("Cannot split {$totalRuns} runs into {$numberOfJobs} jobs");
        }

        // If step is already in a split group, merge first
        if ($step->isSplit()) {
            throw new BadRequestHttpException('Step is already split. Merge first before re-splitting.');
        }

        $splitGroupId = Uuid::v4()->toRfc4122();
        $runsBySplitIndex = SplitGroupQuantities::runsBySplitIndex($totalRuns, $numberOfJobs);
        $quantitiesBySplitIndex = SplitGroupQuantities::share(
            $step->getQuantity(),
            $this->getOutputPerRun($step->getBlueprintTypeId(), $step->getActivityType()),
            $runsBySplitIndex,
        );

        try {
            $this->splitGroupMaterialNeeds->applyChangeOfJobs($project, $step, [$totalRuns], $runsBySplitIndex);
        } catch (BlueprintProductMissingFromSdeException $missingProduct) {
            throw new BadRequestHttpException($missingProduct->getMessage(), $missingProduct);
        }

        // Convert the original step into the first split
        $step->setSplitGroupId($splitGroupId);
        $step->setSplitIndex(0);
        $step->setRuns($runsBySplitIndex[0]);
        $step->setQuantity($quantitiesBySplitIndex[0]);
        $step->setTotalGroupRuns($totalRuns);

        $results = [$step];

        // Create additional split steps
        for ($i = 1; $i < $numberOfJobs; $i++) {
            $newStep = new IndustryProjectStep();
            $newStep->setBlueprintTypeId($step->getBlueprintTypeId());
            $newStep->setProductTypeId($step->getProductTypeId());
            $newStep->setQuantity($quantitiesBySplitIndex[$i]);
            $newStep->setRuns($runsBySplitIndex[$i]);
            $newStep->setDepth($step->getDepth());
            $newStep->setActivityType($step->getActivityType());
            $newStep->setSortOrder($step->getSortOrder());
            $newStep->setSplitGroupId($splitGroupId);
            $newStep->setSplitIndex($i);
            $newStep->setTotalGroupRuns($totalRuns);
            $newStep->setMeLevel($step->getMeLevel());
            $newStep->setTeLevel($step->getTeLevel());
            $newStep->setStructureConfig($step->getStructureConfig());
            $newStep->setJobMatchMode($step->getJobMatchMode());

            $project->addStep($newStep);
            $results[] = $newStep;
        }

        $this->entityManager->flush();

        return array_map(fn ($s) => $this->mapper->stepToResource($s), $results);
    }

    private function getOutputPerRun(int $blueprintTypeId, string $activityType): int
    {
        if ($activityType === 'copy') {
            return self::OUTPUT_PER_COPY_RUN;
        }

        $activityId = match ($activityType) {
            'reaction' => IndustryActivityType::Reaction->value,
            default => IndustryActivityType::Manufacturing->value,
        };

        $product = $this->activityProductRepository->findOneBy([
            'typeId' => $blueprintTypeId,
            'activityId' => $activityId,
        ]);

        if ($product === null) {
            throw new BadRequestHttpException("Product of blueprint {$blueprintTypeId} missing from the SDE");
        }

        return $product->getQuantity();
    }
}
