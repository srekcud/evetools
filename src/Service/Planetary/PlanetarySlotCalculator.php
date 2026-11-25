<?php

declare(strict_types=1);

namespace App\Service\Planetary;

class PlanetarySlotCalculator
{
    private const int BASE_COLONIES = 1;

    public function maxColonies(?int $consolidationLevel): ?int
    {
        if ($consolidationLevel === null) {
            return null;
        }

        return self::BASE_COLONIES + $consolidationLevel;
    }

    /**
     * @param list<array{characterId: int, coloniesUsed: int, consolidationLevel: int|null}> $characters
     *
     * @return array{characters: list<array{characterId: int, coloniesUsed: int, consolidationLevel: int|null, maxColonies: int|null}>, totalUsed: int, totalMax: int|null}
     */
    public function calculate(array $characters): array
    {
        $rows = [];
        $totalUsed = 0;
        $totalMax = 0;
        $totalMaxKnown = true;

        foreach ($characters as $character) {
            $maxColonies = $this->maxColonies($character['consolidationLevel']);
            $rows[] = [
                'characterId' => $character['characterId'],
                'coloniesUsed' => $character['coloniesUsed'],
                'consolidationLevel' => $character['consolidationLevel'],
                'maxColonies' => $maxColonies,
            ];
            $totalUsed += $character['coloniesUsed'];

            if ($maxColonies === null) {
                $totalMaxKnown = false;
            } else {
                $totalMax += $maxColonies;
            }
        }

        return [
            'characters' => $rows,
            'totalUsed' => $totalUsed,
            'totalMax' => $totalMaxKnown ? $totalMax : null,
        ];
    }
}
