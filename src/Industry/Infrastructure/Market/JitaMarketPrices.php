<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Market;

use App\Industry\Application\MarketPrices;
use App\Industry\Application\WeightedPrice;
use App\Industry\Domain\Isk;
use App\Industry\Domain\OrderBookCoverage;
use App\Service\JitaMarketService;

final readonly class JitaMarketPrices implements MarketPrices
{
    public function __construct(private JitaMarketService $jitaMarketService)
    {
    }

    public function unitPricesFor(array $quantitiesByTypeId): array
    {
        $wholeUnitsByTypeId = array_map(
            static fn (float $quantity): int => (int) ceil($quantity),
            $quantitiesByTypeId,
        );

        $unitPrices = [];
        foreach ($this->jitaMarketService->getWeightedSellPricesWithFallback($wholeUnitsByTypeId) as $typeId => $weightedPrice) {
            if (null !== $weightedPrice) {
                $unitPrices[$typeId] = new WeightedPrice(new Isk($weightedPrice['weightedPrice']), new OrderBookCoverage(1.0));
            }
        }

        return $unitPrices;
    }
}
