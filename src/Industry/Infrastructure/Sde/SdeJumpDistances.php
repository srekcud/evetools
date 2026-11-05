<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Application\JumpDistances;
use App\Repository\Sde\MapSolarSystemJumpRepository;

final class SdeJumpDistances implements JumpDistances
{
    private const int JUMPS_WITHIN_A_SYSTEM = 0;

    /** @var array<int, array<int>>|null */
    private ?array $adjacencyList = null;

    public function __construct(private readonly MapSolarSystemJumpRepository $jumpRepository)
    {
    }

    public function jumpsBetween(int $fromSolarSystemId, int $toSolarSystemId): ?int
    {
        if ($fromSolarSystemId === $toSolarSystemId) {
            return self::JUMPS_WITHIN_A_SYSTEM;
        }

        $this->adjacencyList ??= $this->jumpRepository->getAdjacencyList();
        if (!\array_key_exists($fromSolarSystemId, $this->adjacencyList)) {
            return null;
        }

        $jumpsTo = [$fromSolarSystemId => self::JUMPS_WITHIN_A_SYSTEM];
        $frontier = [$fromSolarSystemId];
        while ([] !== $frontier) {
            $nextFrontier = [];
            foreach ($frontier as $solarSystemId) {
                foreach ($this->adjacencyList[$solarSystemId] ?? [] as $neighborId) {
                    if (\array_key_exists($neighborId, $jumpsTo)) {
                        continue;
                    }
                    $jumpsTo[$neighborId] = $jumpsTo[$solarSystemId] + 1;
                    if ($neighborId === $toSolarSystemId) {
                        return $jumpsTo[$neighborId];
                    }
                    $nextFrontier[] = $neighborId;
                }
            }
            $frontier = $nextFrontier;
        }

        return null;
    }
}
