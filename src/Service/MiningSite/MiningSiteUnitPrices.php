<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final class MiningSiteUnitPrices
{
    /**
     * @param list<int> $typeIds
     *
     * @return array<int, ?float>
     */
    public function read(array $typeIds): array
    {
        return array_fill_keys($typeIds, null);
    }
}
