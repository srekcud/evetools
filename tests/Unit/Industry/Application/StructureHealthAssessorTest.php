<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\StructureHealth;
use App\Industry\Application\StructureHealthAssessor;
use App\Industry\Application\StructureHealthState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureHealthAssessor::class)]
#[CoversClass(StructureHealth::class)]
#[Group('issue-342')]
final class StructureHealthAssessorTest extends TestCase
{
    private const string NOW = '2026-11-07 12:00:00';

    public function testLowFuelThresholdIsThreeDays(): void
    {
        self::assertSame(3.0, StructureHealthAssessor::LOW_FUEL_THRESHOLD_DAYS);
    }

    /**
     * @param list<string> $offlineServices
     */
    #[DataProvider('assessments')]
    public function testAssessesTheFuelAndTheServicesOfAStructure(
        ?string $fuelExpiresAt,
        array $offlineServices,
        StructureHealthState $expectedState,
        ?float $expectedFuelDays,
    ): void {
        $health = (new StructureHealthAssessor())->assess(
            null === $fuelExpiresAt ? null : new \DateTimeImmutable($fuelExpiresAt, new \DateTimeZone('UTC')),
            $offlineServices,
            new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')),
        );

        self::assertSame($expectedState, $health->state);
        self::assertSame($expectedFuelDays, $health->fuelDays);
        self::assertSame($offlineServices, $health->offlineServices);
    }

    /**
     * @return iterable<string, array{?string, list<string>, StructureHealthState, ?float}>
     */
    public static function assessments(): iterable
    {
        yield 'plenty of fuel and every service online' => ['2026-12-18 12:00:00', [], StructureHealthState::Ok, 41.0];
        yield 'under three days of fuel' => ['2026-11-09 23:12:00', [], StructureHealthState::LowFuel, 2.5];
        yield 'exactly three days of fuel is not low' => ['2026-11-10 12:00:00', [], StructureHealthState::Ok, 3.0];
        yield 'a service offline with plenty of fuel' => ['2026-12-18 12:00:00', ['Invention'], StructureHealthState::OfflineService, 41.0];
        yield 'an offline service wins over low fuel' => ['2026-11-09 23:12:00', ['Manufacturing', 'Invention'], StructureHealthState::OfflineService, 2.5];
        yield 'fuel already expired is low fuel at zero days' => ['2026-11-06 12:00:00', [], StructureHealthState::LowFuel, 0.0];
        yield 'fuel expiry missing is unknown, never ok' => [null, [], StructureHealthState::Unknown, null];
        yield 'an offline service is reported even when the fuel expiry is missing' => [null, ['Invention'], StructureHealthState::OfflineService, null];
    }

    public function testUnknownHealthHasNoFuelDaysAndNoOfflineService(): void
    {
        $health = StructureHealth::unknown();

        self::assertSame(StructureHealthState::Unknown, $health->state);
        self::assertNull($health->fuelDays);
        self::assertSame([], $health->offlineServices);
    }

    public function testStatesAreSerializedWithTheContractNames(): void
    {
        self::assertSame(
            ['ok', 'low-fuel', 'offline-service', 'unknown'],
            array_map(static fn (StructureHealthState $state): string => $state->value, StructureHealthState::cases()),
        );
    }
}
