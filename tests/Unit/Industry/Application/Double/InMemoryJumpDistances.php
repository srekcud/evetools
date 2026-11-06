<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\JumpDistances;

final class InMemoryJumpDistances implements JumpDistances
{
    private const int JUMPS_WITHIN_A_SYSTEM = 0;

    public array $requestedRoutes = [];

    public function __construct(private readonly array $jumpsByOriginThenDestination = [])
    {
    }

    public function jumpsBetween(int $fromSolarSystemId, int $toSolarSystemId): ?int
    {
        $this->requestedRoutes[] = [$fromSolarSystemId, $toSolarSystemId];

        if ($fromSolarSystemId === $toSolarSystemId) {
            return self::JUMPS_WITHIN_A_SYSTEM;
        }

        return $this->jumpsByOriginThenDestination[$fromSolarSystemId][$toSolarSystemId] ?? null;
    }
}
