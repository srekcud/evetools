<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Input\Industry\CreateProjectInput;
use App\Entity\User;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\InventionService;
use App\Service\Industry\ProductionCostService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Provider\Industry\IndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<CreateProjectInput, ProjectResource>
 */
class CreateProjectProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectFactory $projectFactory,
        private readonly IndustryResourceMapper $mapper,
        private readonly InventionService $inventionService,
        private readonly ProductionCostService $productionCostService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof CreateProjectInput);

        try {
            $meLevel = $data->meLevel;
            $teLevel = $data->teLevel;

            if ($this->inventionService->isT2($data->typeId)) {
                $meLevel = InventionService::BASE_INVENTION_ME;
                $teLevel = InventionService::BASE_INVENTION_TE;
            }

            $project = $this->projectFactory->createProject(
                $user,
                $data->typeId,
                $data->runs,
                $meLevel,
                $data->maxJobDurationDays,
                $teLevel,
                $data->name
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // Calculate estimated costs at creation time (snapshot)
        try {
            $jobInstallResult = $this->productionCostService->estimateJobInstallCosts($project);
            $project->setEstimatedJobCost($jobInstallResult['total']);
        } catch (\Throwable) {
            // Silently ignore - ESI cost indices might not be available
        }

        try {
            $materialResult = $this->productionCostService->estimateMaterialCost($project);
            $project->setEstimatedMaterialCost($materialResult['total']);
        } catch (\Throwable) {
            // Silently ignore - Jita prices might not be available
        }

        try {
            $productTypeId = $project->getProductTypeId();
            $jitaPrice = $this->jitaMarketService->getPrice($productTypeId);

            // Also check user's preferred structure price
            $structurePrice = null;
            $preferredStructureId = $user->getPreferredMarketStructureId();
            if ($preferredStructureId !== null) {
                $structurePrice = $this->structureMarketService->getLowestSellPrice($preferredStructureId, $productTypeId);
            }

            // Pick the higher price (better estimate for seller)
            $bestPrice = null;
            $source = null;
            if ($jitaPrice !== null && $structurePrice !== null) {
                if ($jitaPrice >= $structurePrice) {
                    $bestPrice = $jitaPrice;
                    $source = 'jita';
                } else {
                    $bestPrice = $structurePrice;
                    $source = 'structure';
                }
            } elseif ($jitaPrice !== null) {
                $bestPrice = $jitaPrice;
                $source = 'jita';
            } elseif ($structurePrice !== null) {
                $bestPrice = $structurePrice;
                $source = 'structure';
            }

            if ($bestPrice !== null) {
                $product = $this->activityProductRepository->findBlueprintForProduct($productTypeId, 1);
                $outputPerRun = $product?->getQuantity() ?? 1;
                $project->setEstimatedSellPrice($bestPrice * $project->getRuns() * $outputPerRun);
                $project->setEstimatedSellPriceSource($source);

                // Calculate estimated taxes based on sell price source
                // Jita (NPC): 3.6% sales tax + 2.5% broker fee = 6.1%
                // Structure: 3.6% sales tax + 1.0% broker fee = 4.6%
                $taxRate = $source === 'structure' ? 0.046 : 0.061;
                $estimatedSellTotal = $bestPrice * $project->getRuns() * $outputPerRun;
                $project->setEstimatedTaxAmount($estimatedSellTotal * $taxRate);
            }
        } catch (\Throwable) {
            // Silently ignore - prices might not be available
        }

        $this->entityManager->flush();

        return $this->mapper->projectToResource($project);
    }
}
