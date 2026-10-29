<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;

class EstimatedSellPriceEstimator
{
    private const string SOURCE_JITA = 'jita';
    private const string SOURCE_STRUCTURE = 'structure';

    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryUserSettingsRepository $settingsRepository,
    ) {
    }

    public function estimateFor(IndustryProject $project): ?EstimatedSellPrice
    {
        $user = $project->getUser();
        $productTypeId = $project->getProductTypeId();
        $jitaPrice = $this->jitaMarketService->getPrice($productTypeId);

        $structurePrice = null;
        $preferredStructureId = $user->getPreferredMarketStructureId();
        if ($preferredStructureId !== null) {
            $structurePrice = $this->structureMarketService->getLowestSellPrice($preferredStructureId, $productTypeId);
        }

        if ($structurePrice !== null && ($jitaPrice === null || $structurePrice > $jitaPrice)) {
            $bestPrice = $structurePrice;
            $source = self::SOURCE_STRUCTURE;
        } elseif ($jitaPrice !== null) {
            $bestPrice = $jitaPrice;
            $source = self::SOURCE_JITA;
        } else {
            return null;
        }

        $producer = SplitGroupMaterials::producerOf($this->activityProductRepository, $productTypeId);
        $estimatedSellTotal = $bestPrice * $project->getRuns() * $producer->getQuantity();

        return new EstimatedSellPrice($estimatedSellTotal, $source, $estimatedSellTotal * $this->sellTaxRate($user));
    }

    private function sellTaxRate(User $user): float
    {
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);
        $brokerFeeRate = $settings?->getBrokerFeeRate() ?? EveConstants::DEFAULT_BROKER_FEE_RATE;
        $salesTaxRate = $settings?->getSalesTaxRate() ?? EveConstants::DEFAULT_SALES_TAX_RATE;

        return $brokerFeeRate + $salesTaxRate;
    }
}
