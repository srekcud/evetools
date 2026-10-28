<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\MarketPrices;
use App\Industry\Application\WeightedPrice;
use App\Industry\Domain\Isk;
use App\Industry\Domain\OrderBookCoverage;

/**
 * Fixed unit prices by typeId, whatever the quantity. A typeId without price is absent from the answer (spec R10).
 */
final readonly class InMemoryMarketPrices implements MarketPrices
{
    private const float FULL_COVERAGE = 1.0;

    /**
     * @param array<int, float> $unitPrices by typeId
     */
    public function __construct(private array $unitPrices, private array $coverages = [])
    {
    }

    public function unitPricesFor(array $quantitiesByTypeId): array
    {
        $unitPrices = [];
        foreach (array_keys($quantitiesByTypeId) as $typeId) {
            if (\array_key_exists($typeId, $this->unitPrices)) {
                $unitPrices[$typeId] = new WeightedPrice(new Isk($this->unitPrices[$typeId]), $this->coverageOf($typeId));
            }
        }

        return $unitPrices;
    }

    private function coverageOf(int $typeId): OrderBookCoverage
    {
        return new OrderBookCoverage(
            \array_key_exists($typeId, $this->coverages) ? $this->coverages[$typeId] : self::FULL_COVERAGE,
        );
    }
}
