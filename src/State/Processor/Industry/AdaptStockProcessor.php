<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryStepCalculator;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Adapts project step runs based on current in-stock quantities.
 *
 * @implements ProcessorInterface<mixed, ProjectResource>
 */
class AdaptStockProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryStepCalculator $stepCalculator,
        private readonly IndustryResourceMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(Uuid::fromString($uriVariables['id']));

        if ($project === null || $project->getUser() !== $user) {
            throw new NotFoundHttpException('Project not found');
        }

        // For each intermediate step with stock, adapt runs
        foreach ($project->getSteps() as $step) {
            if ($step->getDepth() === 0 || $step->getActivityType() === 'copy') {
                continue;
            }

            $stock = $step->getInStockQuantity();
            if ($stock <= 0) {
                continue;
            }

            $quantity = $step->getQuantity();
            if ($stock >= $quantity) {
                // Fully in stock — zero runs
                $step->setRuns(0);
                $step->setQuantity(0);
            } else {
                // Partial stock — reduce runs
                $activityId = $step->getActivityType() === 'reaction' ? 11 : 1;
                $product = $this->productRepository->findOneBy([
                    'typeId' => $step->getBlueprintTypeId(),
                    'activityId' => $activityId,
                ]);
                $outputPerRun = $product?->getQuantity() ?? 1;

                $remaining = $quantity - $stock;
                $newRuns = (int) ceil($remaining / $outputPerRun);
                $step->setRuns($newRuns);
                $step->setQuantity($remaining);
            }
        }

        $this->entityManager->flush();

        // Cascade recalculation to update child steps
        $this->stepCalculator->recalculateStepQuantities($project);

        $resource = $this->mapper->projectToResource($project);
        $resource->steps = array_map(
            fn ($step) => $this->mapper->stepToResource($step),
            $project->getSteps()->toArray()
        );

        return $resource;
    }
}
