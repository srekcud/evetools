<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Sde;

use App\Industry\Infrastructure\Sde\SdeJumpDistances;
use App\Repository\Sde\MapSolarSystemJumpRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SdeJumpDistances::class)]
final class SdeJumpDistancesTest extends TestCase
{
    private const int WORKSHOP_SYSTEM_ID = 30002187;
    private const int FIRST_GATE_SYSTEM_ID = 30002188;
    private const int SECOND_GATE_SYSTEM_ID = 30002189;
    private const int THIRD_GATE_SYSTEM_ID = 30004759;
    private const int FIRST_DETOUR_SYSTEM_ID = 30002510;
    private const int SECOND_DETOUR_SYSTEM_ID = 30002511;
    private const int THIRD_DETOUR_SYSTEM_ID = 30002512;
    private const int ISOLATED_SYSTEM_ID = 31000005;
    private const int UNKNOWN_SYSTEM_ID = 30009999;

    public function testSameSystemIsZeroJumpsAwayEvenWhenTheGraphDoesNotKnowIt(): void
    {
        $jumps = $this->jumpDistances()->jumpsBetween(self::UNKNOWN_SYSTEM_ID, self::UNKNOWN_SYSTEM_ID);

        $this->assertSame(0, $jumps);
    }

    public function testSystemThreeStargatesAwayIsThreeJumpsByTheShortestPath(): void
    {
        $jumps = $this->jumpDistances()->jumpsBetween(self::WORKSHOP_SYSTEM_ID, self::THIRD_GATE_SYSTEM_ID);

        $this->assertSame(3, $jumps);
    }

    public function testSystemWithoutAnyStargatePathIsUnknown(): void
    {
        $jumps = $this->jumpDistances()->jumpsBetween(self::WORKSHOP_SYSTEM_ID, self::ISOLATED_SYSTEM_ID);

        $this->assertNull($jumps);
    }

    public function testSystemAbsentFromTheGraphIsUnknown(): void
    {
        $jumps = $this->jumpDistances()->jumpsBetween(self::UNKNOWN_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID);

        $this->assertNull($jumps);
    }

    private function jumpDistances(): SdeJumpDistances
    {
        $repository = $this->createStub(MapSolarSystemJumpRepository::class);
        $repository->method('getAdjacencyList')->willReturn([
            self::WORKSHOP_SYSTEM_ID => [self::FIRST_DETOUR_SYSTEM_ID, self::FIRST_GATE_SYSTEM_ID],
            self::FIRST_GATE_SYSTEM_ID => [self::WORKSHOP_SYSTEM_ID, self::SECOND_GATE_SYSTEM_ID],
            self::SECOND_GATE_SYSTEM_ID => [self::FIRST_GATE_SYSTEM_ID, self::THIRD_GATE_SYSTEM_ID],
            self::THIRD_GATE_SYSTEM_ID => [self::SECOND_GATE_SYSTEM_ID, self::THIRD_DETOUR_SYSTEM_ID],
            self::FIRST_DETOUR_SYSTEM_ID => [self::WORKSHOP_SYSTEM_ID, self::SECOND_DETOUR_SYSTEM_ID],
            self::SECOND_DETOUR_SYSTEM_ID => [self::FIRST_DETOUR_SYSTEM_ID, self::THIRD_DETOUR_SYSTEM_ID],
            self::THIRD_DETOUR_SYSTEM_ID => [self::SECOND_DETOUR_SYSTEM_ID, self::THIRD_GATE_SYSTEM_ID],
            self::ISOLATED_SYSTEM_ID => [],
        ]);

        return new SdeJumpDistances($repository);
    }
}
