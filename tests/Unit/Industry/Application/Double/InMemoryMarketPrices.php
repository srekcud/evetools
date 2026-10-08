<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\MarketPrices;
use App\Industry\Domain\Isk;

/**
 * Fixed unit prices by typeId, whatever the quantity. A typeId without price is absent from the answer (spec R10).
 */
final readonly class InMemoryMarketPrices implements MarketPrices
{
    /**
     * @param array<int, float> $unitPrices by typeId
     */
    public function __construct(private array $unitPrices)
    {
    }

    public function unitPricesFor(array $quantitiesByTypeId): array
    {
        $unitPrices = [];
        foreach (array_keys($quantitiesByTypeId) as $typeId) {
            if (\array_key_exists($typeId, $this->unitPrices)) {
                $unitPrices[$typeId] = new Isk($this->unitPrices[$typeId]);
            }
        }

        return $unitPrices;
    }
}
