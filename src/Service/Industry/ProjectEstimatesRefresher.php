<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use Psr\Log\LoggerInterface;

class ProjectEstimatesRefresher
{
    public function __construct(
        private readonly ProductionCostService $productionCostService,
        private readonly EstimatedSellPriceEstimator $sellPriceEstimator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function refresh(IndustryProject $project): void
    {
    }
}
