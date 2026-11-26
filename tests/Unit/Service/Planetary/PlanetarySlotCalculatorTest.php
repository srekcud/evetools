<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Service\Planetary\PlanetarySlotCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetarySlotCalculator::class)]
#[Group('issue-1384')]
final class PlanetarySlotCalculatorTest extends TestCase
{
    private const int PILOT_A = 2_113_840_001;
    private const int PILOT_B = 2_113_840_002;
    private const int PILOT_C = 2_113_840_003;
    private const int PILOT_D = 2_113_840_004;

    public function testAPilotWithoutTheSkillHasOneColonySlot(): void
    {
        self::assertSame(1, (new PlanetarySlotCalculator())->maxColonies(0));
    }

    public function testAPilotWithTheSkillAtLevelFiveHasSixColonySlots(): void
    {
        self::assertSame(6, (new PlanetarySlotCalculator())->maxColonies(5));
    }

    public function testAPilotWithTheSkillAtLevelTwoHasThreeColonySlots(): void
    {
        self::assertSame(3, (new PlanetarySlotCalculator())->maxColonies(2));
    }

    public function testTheMaximumIsUnknownWhenTheSkillLevelIsUnknown(): void
    {
        self::assertNull((new PlanetarySlotCalculator())->maxColonies(null));
    }

    public function testTotalsAddUpTheColoniesUsedAndTheColonySlotsOfThePilots(): void
    {
        $summary = (new PlanetarySlotCalculator())->calculate([
            ['characterId' => self::PILOT_A, 'coloniesUsed' => 6, 'consolidationLevel' => 5],
            ['characterId' => self::PILOT_B, 'coloniesUsed' => 3, 'consolidationLevel' => 4],
            ['characterId' => self::PILOT_C, 'coloniesUsed' => 0, 'consolidationLevel' => 2],
        ]);

        self::assertSame(
            [
                [
                    'characterId' => self::PILOT_A,
                    'coloniesUsed' => 6,
                    'consolidationLevel' => 5,
                    'maxColonies' => 6,
                ],
                [
                    'characterId' => self::PILOT_B,
                    'coloniesUsed' => 3,
                    'consolidationLevel' => 4,
                    'maxColonies' => 5,
                ],
                [
                    'characterId' => self::PILOT_C,
                    'coloniesUsed' => 0,
                    'consolidationLevel' => 2,
                    'maxColonies' => 3,
                ],
            ],
            $summary['characters'],
        );
        self::assertSame(9, $summary['totalUsed']);
        self::assertSame(14, $summary['totalMax']);
    }

    public function testTotalMaximumIsUnknownAsSoonAsOnePilotHasAnUnknownMaximum(): void
    {
        $summary = (new PlanetarySlotCalculator())->calculate([
            ['characterId' => self::PILOT_A, 'coloniesUsed' => 6, 'consolidationLevel' => 5],
            ['characterId' => self::PILOT_B, 'coloniesUsed' => 3, 'consolidationLevel' => 4],
            ['characterId' => self::PILOT_C, 'coloniesUsed' => 0, 'consolidationLevel' => 2],
            ['characterId' => self::PILOT_D, 'coloniesUsed' => 2, 'consolidationLevel' => null],
        ]);

        self::assertSame(11, $summary['totalUsed']);
        self::assertNull($summary['totalMax']);
        self::assertSame(
            ['characterId' => self::PILOT_D, 'coloniesUsed' => 2, 'consolidationLevel' => null, 'maxColonies' => null],
            $summary['characters'][3] ?? null,
        );
    }

    public function testAPilotWithoutColonyStillOffersItsSlots(): void
    {
        $summary = (new PlanetarySlotCalculator())->calculate([
            ['characterId' => self::PILOT_C, 'coloniesUsed' => 0, 'consolidationLevel' => 4],
        ]);

        self::assertSame(0, $summary['totalUsed']);
        self::assertSame(5, $summary['totalMax']);
        self::assertSame(5, $summary['characters'][0]['maxColonies'] ?? null);
    }

    public function testColoniesUsedAboveTheMaximumAreKeptAsIs(): void
    {
        $summary = (new PlanetarySlotCalculator())->calculate([
            ['characterId' => self::PILOT_B, 'coloniesUsed' => 4, 'consolidationLevel' => 1],
        ]);

        self::assertSame(4, $summary['totalUsed']);
        self::assertSame(2, $summary['totalMax']);
        self::assertSame(
            ['characterId' => self::PILOT_B, 'coloniesUsed' => 4, 'consolidationLevel' => 1, 'maxColonies' => 2],
            $summary['characters'][0] ?? null,
        );
    }

    public function testTheTotalsAreZeroForAnAccountWithoutPilot(): void
    {
        $summary = (new PlanetarySlotCalculator())->calculate([]);

        self::assertSame([], $summary['characters']);
        self::assertSame(0, $summary['totalUsed']);
        self::assertSame(0, $summary['totalMax']);
    }
}
