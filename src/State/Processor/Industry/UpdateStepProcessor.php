<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectStepResource;
use App\ApiResource\Input\Industry\UpdateStepInput;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStepRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryProjectService;
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
        private readonly IndustryProjectService $projectService,
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

        $step = $this->stepRepository->find(Uuid::fromString($uriVariables['stepId']));

        if ($step === null || $step->getProject() !== $project) {
            throw new NotFoundHttpException('Step not found');
        }

        assert($data instanceof UpdateStepInput);

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
            $step->setRuns($data->runs);
            $quantityPerRun = $this->getQuantityPerRun($step->getBlueprintTypeId(), $step->getActivityType());
            $step->setQuantity($data->runs * $quantityPerRun);
            $needsCascade = true;
        }

        $this->entityManager->flush();

        if ($needsCascade) {
            $cascadedSteps = $this->projectService->recalculateStepQuantities($project);

            if (!empty($cascadedSteps)) {
                $updatedStepResources = [$this->mapper->stepToResource($step)];
                foreach ($cascadedSteps as $updatedStep) {
                    if ($updatedStep !== $step) {
                        $updatedStepResources[] = $this->mapper->stepToResource($updatedStep);
                    }
                }
                return $updatedStepResources;
            }
        }

        return $this->mapper->stepToResource($step);
    }

    private function getQuantityPerRun(int $blueprintTypeId, string $activityType): int
    {
        $activityId = match ($activityType) {
            'manufacturing' => 1,
            'reaction' => 11,
            default => 1,
        };

        $product = $this->activityProductRepository->findOneBy([
            'typeId' => $blueprintTypeId,
            'activityId' => $activityId,
        ]);

        return $product?->getQuantity() ?? 1;
    }
}
