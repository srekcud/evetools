<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;

class EstimatedSellPriceEstimator
{
    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryUserSettingsRepository $settingsRepository,
    ) {
    }

    public function estimateFor(IndustryProject $project): ?EstimatedSellPrice
    {
        return null;
    }
}
