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
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UpdateStepInput, ProjectStepResource>
 */
class UpdateStepProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectStepRepository $stepRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryProjectService $projectService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStepResource
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

        if (!$data instanceof UpdateStepInput) {
            throw new BadRequestHttpException('Invalid input');
        }

        if ($data->purchased !== null) {
            if ($step->getDepth() === 0 && $data->purchased) {
                throw new BadRequestHttpException('Cannot mark root products as purchased');
            }
            $step->setPurchased($data->purchased);
            if ($data->purchased) {
                $step->clearJobData();
            }
        }

        if ($data->inStockQuantity !== null) {
            if ($step->getDepth() === 0 && $data->inStockQuantity > 0) {
                throw new BadRequestHttpException('Cannot mark root products as in stock');
            }
            $step->setInStockQuantity($data->inStockQuantity);
            if ($step->isInStock()) {
                $step->clearJobData();
            }
        } elseif ($data->inStock !== null) {
            // Legacy boolean support: set full quantity if true, 0 if false
            if ($step->getDepth() === 0 && $data->inStock) {
                throw new BadRequestHttpException('Cannot mark root products as in stock');
            }
            $step->setInStockQuantity($data->inStock ? $step->getQuantity() : 0);
            if ($data->inStock) {
                $step->clearJobData();
            }
        }

        if ($data->clearJobData === true) {
            $step->clearJobData();
        }

        if ($data->esiJobsTotalRuns !== null) {
            $step->setEsiJobsTotalRuns($data->esiJobsTotalRuns);
            $step->setManualJobData(true);
        }
        if ($data->esiJobCost !== null) {
            $step->setEsiJobCost($data->esiJobCost);
            $step->setManualJobData(true);
        }
        if ($data->esiJobStatus !== null) {
            $step->setEsiJobStatus($data->esiJobStatus);
            $step->setManualJobData(true);
        }
        if ($data->esiJobCharacterName !== null) {
            $step->setEsiJobCharacterName($data->esiJobCharacterName);
            $step->setManualJobData(true);
        }
        if ($data->esiJobsCount !== null) {
            $step->setEsiJobsCount($data->esiJobsCount);
            $step->setManualJobData(true);
        }
        if ($data->manualJobData !== null) {
            $step->setManualJobData($data->manualJobData);
        }

        if ($data->runs !== null && $data->runs >= 1) {
            $step->setRuns($data->runs);
            $quantityPerRun = $this->getQuantityPerRun($step->getBlueprintTypeId(), $step->getActivityType());
            $step->setQuantity($data->runs * $quantityPerRun);
        }

        $this->entityManager->flush();

        return $this->toResource($this->projectService->serializeStep($step));
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

    private function toResource(array $step): ProjectStepResource
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
        $resource->inStockQuantity = $step['inStockQuantity'] ?? 0;
        $resource->esiJobsTotalRuns = $step['esiJobsTotalRuns'] ?? null;
        $resource->esiJobCost = $step['esiJobCost'] ?? null;
        $resource->esiJobStatus = $step['esiJobStatus'] ?? null;
        $resource->esiJobCharacterName = $step['esiJobCharacterName'] ?? null;
        $resource->esiJobsCount = $step['esiJobsCount'] ?? null;
        $resource->manualJobData = $step['manualJobData'] ?? false;

        return $resource;
    }
}
