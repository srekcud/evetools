<?php

declare(strict_types=1);

namespace App\Service\Planetary;

class PlanetarySlotCalculator
{
    public function maxColonies(?int $consolidationLevel): ?int
    {
        return null;
    }

    /**
     * @param list<array{characterId: int, coloniesUsed: int, consolidationLevel: int|null}> $characters
     *
     * @return array{characters: list<array{characterId: int, coloniesUsed: int, consolidationLevel: int|null, maxColonies: int|null}>, totalUsed: int, totalMax: int|null}
     */
    public function calculate(array $characters): array
    {
        return ['characters' => [], 'totalUsed' => 0, 'totalMax' => null];
    }
}
