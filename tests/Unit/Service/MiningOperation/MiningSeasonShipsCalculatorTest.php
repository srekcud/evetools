<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MiningSeasonCalculator;
use App\Service\MiningOperation\MiningSeasonCharacter;
use App\Service\MiningOperation\MiningSeasonOperation;
use App\Service\MiningOperation\MiningSeasonSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningSeasonCalculator::class)]
#[CoversClass(MiningSeasonSummary::class)]
#[CoversClass(MiningSeasonCharacter::class)]
#[Group('issue-364')]
final class MiningSeasonShipsCalculatorTest extends TestCase
{
    private const int HULK = 22544;
    private const int SKIFF = 22546;
    private const int MACKINAW = 22548;
    private const int ORCA = 28606;

    private MiningSeasonCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MiningSeasonCalculator();
    }

    public function testCharactersOfEachShipTypeAreSummedAndTheShipsAreSortedByIskPerHour(): void
    {
        $summary = $this->calculator->summarise([
            $this->knownOperation(120, [
                $this->miner('hulk-1', self::HULK, 120, 70_000_000),
                $this->miner('hulk-2', self::HULK, 120, 50_000_000),
                $this->miner('skiff-1', self::SKIFF, 90, 20_000_000),
            ]),
        ]);

        self::assertSame(
            [
                [self::HULK, 2, 240, 120_000_000, 30_000_000, false],
                [self::SKIFF, 1, 90, 20_000_000, 13_333_333, false],
            ],
            $this->shipsOf($summary),
        );
    }

    public function testCharacterOfTwoOperationsCountsOnceButItsMinutesAndValueAdd(): void
    {
        $summary = $this->calculator->summarise([
            $this->knownOperation(120, [
                $this->miner('hulk-1', self::HULK, 120, 70_000_000),
                $this->miner('hulk-2', self::HULK, 120, 50_000_000),
                $this->miner('skiff-1', self::SKIFF, 90, 20_000_000),
            ]),
            $this->knownOperation(60, [$this->miner('hulk-1', self::HULK, 60, 30_000_000)]),
        ]);

        self::assertSame(
            [
                [self::HULK, 2, 300, 150_000_000, 30_000_000, false],
                [self::SKIFF, 1, 90, 20_000_000, 13_333_333, false],
            ],
            $this->shipsOf($summary),
        );
    }

    public function testBoosterShipHasNeitherValueNorIskPerHourAndComesLastWithItsMinutes(): void
    {
        $summary = $this->calculator->summarise([
            $this->knownOperation(120, [
                $this->booster('orca-1', self::ORCA, 120, null),
                $this->miner('hulk-1', self::HULK, 120, 40_000_000),
            ]),
        ]);

        self::assertSame(
            [
                [self::HULK, 1, 120, 40_000_000, 20_000_000, false],
                [self::ORCA, 1, 120, null, null, true],
            ],
            $this->shipsOf($summary),
        );
    }

    public function testCharacterWithoutAShipIsOutOfTheShipsAndCountedOncePerOperation(): void
    {
        $withoutShip = $this->miner('no-ship-1', null, 120, 30_000_000);
        $operationA = $this->knownOperation(120, [$this->miner('hulk-1', self::HULK, 120, 40_000_000), $withoutShip]);
        $operationB = $this->knownOperation(60, [$withoutShip]);

        $afterA = $this->calculator->summarise([$operationA]);
        $afterAandB = $this->calculator->summarise([$operationA, $operationB]);

        self::assertSame(
            [[[self::HULK, 1, 120, 40_000_000, 20_000_000, false]], 1, 2],
            [$this->shipsOf($afterA), $afterA->unknownShipCharactersCount, $afterAandB->unknownShipCharactersCount],
        );
    }

    public function testCharactersOfAnUnknownOperationEnterNeitherTheShipsNorTheUnknownShipCount(): void
    {
        $summary = $this->calculator->summarise([
            new MiningSeasonOperation(120, null, [], [
                $this->miner('hulk-1', self::HULK, 120, null),
                $this->miner('no-ship-1', null, 120, null),
            ]),
        ]);

        self::assertSame([[], 0], [$this->shipsOf($summary), $summary->unknownShipCharactersCount]);
    }

    public function testNoOperationGivesNoShipAndNoUnknownShipCharacter(): void
    {
        $summary = $this->calculator->summarise([]);

        self::assertSame([[], 0], [$this->shipsOf($summary), $summary->unknownShipCharactersCount]);
    }

    public function testIskPerHourOfAShipIsRoundedToTheNearestIsk(): void
    {
        $summary = $this->calculator->summarise([$this->knownOperation(7, [$this->miner('hulk-1', self::HULK, 7, 1_000_000)])]);

        self::assertSame([[self::HULK, 1, 7, 1_000_000, 8_571_429, false]], $this->shipsOf($summary));
    }

    public function testIskPerHourOfAShipRoundsAHalfIskUp(): void
    {
        $summary = $this->calculator->summarise([$this->knownOperation(24, [$this->miner('hulk-1', self::HULK, 24, 5)])]);

        self::assertSame([[self::HULK, 1, 24, 5, 13, false]], $this->shipsOf($summary));
    }

    public function testCharacterPresentForZeroMinutesKeepsItsValueButHasNoIskPerHour(): void
    {
        $summary = $this->calculator->summarise([$this->knownOperation(120, [$this->miner('hulk-1', self::HULK, 0, 5_000_000)])]);

        self::assertSame([[self::HULK, 1, 0, 5_000_000, null, false]], $this->shipsOf($summary));
    }

    public function testShipsWithTheSameIskPerHourAreSortedByTypeIdAndThoseWithoutIskPerHourComeLastByTypeId(): void
    {
        $summary = $this->calculator->summarise([
            $this->knownOperation(120, [
                $this->booster('orca-1', self::ORCA, 120, 9_000_000),
                $this->miner('mackinaw-1', self::MACKINAW, 0, 5_000_000),
                $this->miner('skiff-1', self::SKIFF, 60, 10_000_000),
                $this->miner('hulk-1', self::HULK, 120, 20_000_000),
            ]),
        ]);

        self::assertSame(
            [
                [self::HULK, 1, 120, 20_000_000, 10_000_000, false],
                [self::SKIFF, 1, 60, 10_000_000, 10_000_000, false],
                [self::MACKINAW, 1, 0, 5_000_000, null, false],
                [self::ORCA, 1, 120, null, null, true],
            ],
            $this->shipsOf($summary),
        );
    }

    public function testMinerOfAKnownOperationWithoutMinedValueIsRefusedInsteadOfCountedAsZero(): void
    {
        $this->expectException(\LogicException::class);

        $this->calculator->summarise([$this->knownOperation(120, [$this->miner('hulk-1', self::HULK, 120, null)])]);
    }

    private function knownOperation(int $minutes, array $characters): MiningSeasonOperation
    {
        $value = array_sum(array_map(static fn (MiningSeasonCharacter $character): int => $character->isBooster ? 0 : (int) $character->minedValue, $characters));

        return new MiningSeasonOperation($minutes, $value, [], $characters);
    }

    private function miner(string $characterKey, ?int $shipTypeId, int $minutes, ?int $minedValue): MiningSeasonCharacter
    {
        return new MiningSeasonCharacter($characterKey, $shipTypeId, $minutes, $minedValue, false);
    }

    private function booster(string $characterKey, int $shipTypeId, int $minutes, ?int $minedValue): MiningSeasonCharacter
    {
        return new MiningSeasonCharacter($characterKey, $shipTypeId, $minutes, $minedValue, true);
    }

    private function shipsOf(MiningSeasonSummary $summary): array
    {
        return array_map(
            static fn (array $ship): array => [$ship['shipTypeId'], $ship['charactersCount'], $ship['minutes'], $ship['minedValue'], $ship['iskPerHour'], $ship['isBooster']],
            $summary->ships,
        );
    }
}
