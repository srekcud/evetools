<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;

class EstimatedSellPriceEstimator
{
    private const string SOURCE_JITA = 'jita';
    private const string SOURCE_STRUCTURE = 'structure';
    private const string SOURCE_MIXED = 'mixed';

    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly SellerSalesFeesResolver $salesFeesResolver,
    ) {
    }

    public function estimateFor(IndustryProject $project): ?EstimatedSellPrice
    {
        $user = $project->getUser();
        $projectProductTypeId = $project->getProductTypeId();
        $producer = SplitGroupMaterials::producerOf($this->activityProductRepository, $projectProductTypeId);

        $quantitiesByProductTypeId = [$projectProductTypeId => $project->getRuns() * $producer->getQuantity()];
        foreach ($project->getSteps() as $step) {
            if ($this->isAdditionalRootProduct($step, $projectProductTypeId)) {
                $quantitiesByProductTypeId[$step->getProductTypeId()] = ($quantitiesByProductTypeId[$step->getProductTypeId()] ?? 0) + $step->getQuantity();
            }
        }

        $estimatedSellTotal = 0.0;
        $sources = [];
        foreach ($quantitiesByProductTypeId as $productTypeId => $quantity) {
            $bestPrice = $this->bestPriceOf($user, $productTypeId);
            if ($bestPrice === null) {
                return null;
            }
            $estimatedSellTotal += $bestPrice['price'] * $quantity;
            $sources[$bestPrice['source']] = true;
        }

        $source = count($sources) === 1 ? array_key_first($sources) : self::SOURCE_MIXED;

        $salesFees = $this->salesFeesResolver->forUser($user);
        $taxAmount = null === $salesFees ? null : $estimatedSellTotal * ($salesFees->brokerFeeRate + $salesFees->salesTaxRate);

        return new EstimatedSellPrice($estimatedSellTotal, $source, $taxAmount);
    }

    private function isAdditionalRootProduct(IndustryProjectStep $step, int $projectProductTypeId): bool
    {
        return $step->getDepth() === 0
            && in_array($step->getActivityType(), ['manufacturing', 'reaction'], true)
            && $step->getProductTypeId() !== $projectProductTypeId;
    }

    /**
     * @return array{price: float, source: string}|null
     */
    private function bestPriceOf(User $user, int $productTypeId): ?array
    {
        $jitaPrice = $this->jitaMarketService->getPrice($productTypeId);

        $structurePrice = null;
        $preferredStructureId = $user->getPreferredMarketStructureId();
        if ($preferredStructureId !== null) {
            $structurePrice = $this->structureMarketService->getLowestSellPrice($preferredStructureId, $productTypeId);
        }

        if ($structurePrice !== null && ($jitaPrice === null || $structurePrice > $jitaPrice)) {
            return ['price' => $structurePrice, 'source' => self::SOURCE_STRUCTURE];
        }

        if ($jitaPrice !== null) {
            return ['price' => $jitaPrice, 'source' => self::SOURCE_JITA];
        }

        return null;
    }
}
