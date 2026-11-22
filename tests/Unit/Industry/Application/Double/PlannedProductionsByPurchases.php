<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\PlannedProduction;

final class PlannedProductionsByPurchases
{
    public array $calls = [];

    public function __construct(private readonly array $plannedProductionsByPurchases)
    {
    }

    public function closure(): \Closure
    {
        return function (array $boughtTypeIds): PlannedProduction {
            $this->calls[] = $boughtTypeIds;
            $sorted = $boughtTypeIds;
            sort($sorted);
            $key = implode(',', $sorted);

            return $this->plannedProductionsByPurchases[$key]
                ?? throw new \OutOfBoundsException(\sprintf('No planned production prepared for the purchases "%s".', $key));
        };
    }

    public function sortedCalls(): array
    {
        return array_map(static function (array $typeIds): array {
            sort($typeIds);

            return $typeIds;
        }, $this->calls);
    }
}
