<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\CreateStepInput;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
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
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

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

        // If stepId is provided, add a child to a single step (create split group)
        if ($stepId !== null) {
            $existingStep = $this->stepRepository->find(Uuid::fromString($stepId));

            if ($existingStep === null || $existingStep->getProject() !== $project) {
                throw new NotFoundHttpException('Step not found');
            }

            $newSplitGroupId = Uuid::v4()->toRfc4122();
            $totalRuns = $existingStep->getRuns() + $runs;

            $existingStep->setSplitGroupId($newSplitGroupId);
            $existingStep->setSplitIndex(0);
            $existingStep->setTotalGroupRuns($totalRuns);

            $step = new IndustryProjectStep();
            $step->setBlueprintTypeId($existingStep->getBlueprintTypeId());
            $step->setProductTypeId($existingStep->getProductTypeId());
            $step->setQuantity($runs);
            $step->setRuns($runs);
            $step->setDepth($existingStep->getDepth());
            $step->setActivityType($existingStep->getActivityType());
            $step->setSortOrder($existingStep->getSortOrder());
            $step->setSplitGroupId($newSplitGroupId);
            $step->setSplitIndex(1);
            $step->setTotalGroupRuns($totalRuns);
            $step->setMeLevel($existingStep->getMeLevel());
            $step->setTeLevel($existingStep->getTeLevel());
            $step->setStructureConfig($existingStep->getStructureConfig());

            $project->addStep($step);
            $this->entityManager->flush();

            return [
                'newStep' => $this->mapper->stepToResource($step),
                'updatedStep' => $this->mapper->stepToResource($existingStep),
            ];
        }

        // If splitGroupId is provided, add to existing split group
        if ($splitGroupId !== null) {
            $existingStep = null;
            $maxSplitIndex = -1;
            foreach ($project->getSteps() as $s) {
                if ($s->getSplitGroupId() === $splitGroupId) {
                    if ($existingStep === null) {
                        $existingStep = $s;
                    }
                    if ($s->getSplitIndex() > $maxSplitIndex) {
                        $maxSplitIndex = $s->getSplitIndex();
                    }
                }
            }

            if ($existingStep === null) {
                throw new NotFoundHttpException('Split group not found');
            }

            $step = new IndustryProjectStep();
            $step->setBlueprintTypeId($existingStep->getBlueprintTypeId());
            $step->setProductTypeId($existingStep->getProductTypeId());
            $step->setQuantity($runs);
            $step->setRuns($runs);
            $step->setDepth($existingStep->getDepth());
            $step->setActivityType($existingStep->getActivityType());
            $step->setSortOrder($existingStep->getSortOrder());
            $step->setSplitGroupId($splitGroupId);
            $step->setSplitIndex($maxSplitIndex + 1);
            $step->setTotalGroupRuns($existingStep->getTotalGroupRuns());
            $step->setMeLevel($existingStep->getMeLevel());
            $step->setTeLevel($existingStep->getTeLevel());
            $step->setStructureConfig($existingStep->getStructureConfig());

            $project->addStep($step);
            $this->entityManager->flush();

            return $this->mapper->stepToResource($step);
        }

        // Create new step from typeId
        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            throw new BadRequestHttpException('Unknown type');
        }

        $activityProduct = $this->activityProductRepository->findOneBy([
            'productTypeId' => $typeId,
            'activityId' => 1,
        ]);

        if ($activityProduct === null) {
            $activityProduct = $this->activityProductRepository->findOneBy([
                'productTypeId' => $typeId,
                'activityId' => 11,
            ]);
        }

        $blueprintTypeId = $activityProduct?->getTypeId() ?? $typeId;
        $activityType = match ($activityProduct?->getActivityId()) {
            11 => 'reaction',
            default => 'manufacturing',
        };

        $maxSortOrder = 0;
        foreach ($project->getSteps() as $s) {
            if ($s->getSortOrder() > $maxSortOrder) {
                $maxSortOrder = $s->getSortOrder();
            }
        }

        $step = new IndustryProjectStep();
        $step->setBlueprintTypeId($blueprintTypeId);
        $step->setProductTypeId($typeId);
        $step->setQuantity($runs);
        $step->setRuns($runs);
        $step->setDepth(0);
        $step->setActivityType($activityType);
        $step->setSortOrder($maxSortOrder + 1);
        $step->setMeLevel($meLevel);
        $step->setTeLevel($teLevel);

        $project->addStep($step);
        $this->entityManager->flush();

        return $this->mapper->stepToResource($step);
    }
}
