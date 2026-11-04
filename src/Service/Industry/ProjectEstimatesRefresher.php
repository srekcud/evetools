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
        $project->setEstimatedJobCost(null);
        try {
            $project->setEstimatedJobCost($this->productionCostService->estimateJobInstallCosts($project)['total']);
        } catch (\Throwable $e) {
            $this->logEstimationFailure('job_install_cost', $project, $e);
        }

        $project->setEstimatedMaterialCost(null);
        try {
            $project->setEstimatedMaterialCost($this->productionCostService->estimateMaterialCost($project)['total']);
        } catch (\Throwable $e) {
            $this->logEstimationFailure('material_cost', $project, $e);
        }

        $project->setEstimatedSellPrice(null)->setEstimatedSellPriceSource(null)->setEstimatedTaxAmount(null);
        try {
            $estimatedSellPrice = $this->sellPriceEstimator->estimateFor($project);
            if ($estimatedSellPrice !== null) {
                $project->recordEstimatedSellPrice($estimatedSellPrice->amount, $estimatedSellPrice->source, $estimatedSellPrice->taxAmount);
            }
        } catch (\Throwable $e) {
            $this->logEstimationFailure('sell_price', $project, $e);
        }
    }

    private function logEstimationFailure(string $estimation, IndustryProject $project, \Throwable $exception): void
    {
        $this->logger->warning('Industry project estimation failed', [
            'estimation' => $estimation,
            'projectId' => $project->getId()?->toRfc4122(),
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
