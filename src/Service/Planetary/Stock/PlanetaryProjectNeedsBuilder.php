<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryProjectNeedsBuilder
{
    /**
     * @param list<ProjectLineDemand> $demands
     * @param array<int, int>|null $personalQuantities typeId => quantity, null when the stock is unknown
     *
     * @return list<PlanetaryProjectNeed>
     */
    public function build(array $demands, ?array $personalQuantities): array
    {
        return [];
    }
}
