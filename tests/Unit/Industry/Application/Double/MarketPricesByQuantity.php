<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\MarketPrices;
use App\Industry\Application\WeightedPrice;
use App\Industry\Domain\Isk;
use App\Industry\Domain\OrderBookCoverage;

final class MarketPricesByQuantity implements MarketPrices
{
    private const float FULL_COVERAGE = 1.0;

    public array $requestedQuantities = [];

    public function __construct(private readonly array $unitPriceByQuantity)
    {
    }

    public function unitPricesFor(array $quantitiesByTypeId): array
    {
        $this->requestedQuantities[] = $quantitiesByTypeId;

        $unitPrices = [];
        foreach ($quantitiesByTypeId as $typeId => $quantity) {
            $unitPrice = $this->unitPriceByQuantity[(int) $quantity]
                ?? throw new \OutOfBoundsException(\sprintf('No unit price set for a quantity of %s.', var_export($quantity, true)));
            $unitPrices[$typeId] = new WeightedPrice(new Isk($unitPrice), new OrderBookCoverage(self::FULL_COVERAGE));
        }

        return $unitPrices;
    }
}
