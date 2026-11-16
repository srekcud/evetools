<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Service\JitaMarketService;

class ProjectLaunchPriceFreezer
{
    public function __construct(private readonly JitaMarketService $jitaMarket)
    {
    }

    public function freeze(IndustryProject $project): void
    {
        $materialLines = array_values(array_filter(
            $project->getBomItems()->toArray(),
            static fn (IndustryProjectBomLine $line): bool => $line->isPurchasable(),
        ));

        if ([] === $materialLines) {
            return;
        }

        $typeIds = array_values(array_unique(array_map(static fn (IndustryProjectBomLine $line): int => $line->getTypeId(), $materialLines)));
        $prices = $this->jitaMarket->getCheapestPercentilePrices($typeIds, ProjectContributionService::DEPOSIT_DAY_PERCENTILE);

        foreach ($materialLines as $line) {
            $price = $prices[$line->getTypeId()] ?? null;
            if (null === $line->getLaunchPrice() && null !== $price && $price > 0) {
                $line->setLaunchPrice($price);
            }
        }
    }
}
