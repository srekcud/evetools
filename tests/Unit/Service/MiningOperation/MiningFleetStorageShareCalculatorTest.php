<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningHaulerPlanUnknownReason;
use App\Service\MiningOperation\MiningFleetStorageContribution;
use App\Service\MiningOperation\MiningFleetStorageShare;
use App\Service\MiningOperation\MiningFleetStorageShareCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningFleetStorageShareCalculator::class)]
#[CoversClass(MiningFleetStorageShare::class)]
#[CoversClass(MiningFleetStorageContribution::class)]
#[Group('issue-312')]
final class MiningFleetStorageShareCalculatorTest extends TestCase
{
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const int PYROXERES = 1224;

    private MiningFleetStorageShareCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MiningFleetStorageShareCalculator();
    }

    public function testShareIsTheDeliveredUnitsOverTheMinedUnitsOfEveryoneIncludingTheRecipient(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor([self::VELDSPAR => 4_000, self::SCORDITE => 2_000], MiningFleetStorageDelivery::All),
            $this->depositor([self::VELDSPAR => 3_000], MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 1_000]),
            $this->depositor([self::SCORDITE => 1_000], MiningFleetStorageDelivery::None),
            $this->recipient([self::VELDSPAR => 1_500, self::SCORDITE => 500]),
        ]);

        self::assertSame([12_000, 7_000, null], [$share->minedUnits, $share->deliveredUnits, $share->unknownReason]);
        self::assertEqualsWithDelta(7 / 12, $share->share, 0.000001);
    }

    public function testPartialDeclarationIsCappedAtWhatTheParticipantMinedOfThatType(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor([self::VELDSPAR => 3_000], MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 5_000]),
            $this->recipient([self::VELDSPAR => 3_000]),
        ]);

        self::assertSame([6_000, 3_000], [$share->minedUnits, $share->deliveredUnits]);
        self::assertEqualsWithDelta(0.5, $share->share, 0.000001);
    }

    public function testPartialDeclarationSumsTheCappedQuantityOfEachTypeAndIgnoresATypeNeverMined(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor(
                [self::VELDSPAR => 2_000, self::SCORDITE => 900],
                MiningFleetStorageDelivery::Partial,
                [self::VELDSPAR => 600, self::SCORDITE => 1_500, self::PYROXERES => 700],
            ),
            $this->recipient([self::VELDSPAR => 1_100]),
        ]);

        self::assertSame([4_000, 1_500], [$share->minedUnits, $share->deliveredUnits]);
        self::assertEqualsWithDelta(0.375, $share->share, 0.000001);
    }

    public function testRecipientCountsInTheTotalButNeverInTheDeliveredEvenWithAnAllDeclaration(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor([self::VELDSPAR => 1_000], MiningFleetStorageDelivery::All),
            new MiningFleetStorageContribution([self::SCORDITE => 3_000], MiningFleetStorageDelivery::All, null, true),
        ]);

        self::assertSame([4_000, 1_000], [$share->minedUnits, $share->deliveredUnits]);
        self::assertEqualsWithDelta(0.25, $share->share, 0.000001);
    }

    public function testOnlyNoneDepositorsMeansNothingDelivered(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor([self::VELDSPAR => 1_000], MiningFleetStorageDelivery::None),
            $this->recipient([self::VELDSPAR => 2_000]),
        ]);

        self::assertSame([MiningHaulerPlanUnknownReason::NothingDelivered, null], [$share->unknownReason, $share->share]);
    }

    public function testUnreadStartSnapshotOfAnyParticipantMakesTheLedgerUnknownForTheWholePlan(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor([self::VELDSPAR => 4_000], MiningFleetStorageDelivery::All),
            $this->depositor(null, MiningFleetStorageDelivery::All),
            $this->recipient([self::VELDSPAR => 1_000]),
        ]);

        self::assertSame([MiningHaulerPlanUnknownReason::LedgerUnknown, null, null, null], [$share->unknownReason, $share->share, $share->minedUnits, $share->deliveredUnits]);
    }

    public function testNothingMinedSinceTheStartMakesTheLedgerUnknown(): void
    {
        $share = $this->calculator->calculate([
            $this->depositor([], MiningFleetStorageDelivery::All),
            $this->recipient([]),
        ]);

        self::assertSame([MiningHaulerPlanUnknownReason::LedgerUnknown, null], [$share->unknownReason, $share->share]);
    }

    public function testNoParticipantMakesTheLedgerUnknown(): void
    {
        self::assertSame(MiningHaulerPlanUnknownReason::LedgerUnknown, $this->calculator->calculate([])->unknownReason);
    }

    /**
     * @param array<int, int>|null $mined
     * @param array<int, int>|null $declared
     */
    private function depositor(?array $mined, MiningFleetStorageDelivery $delivery, ?array $declared = null): MiningFleetStorageContribution
    {
        return new MiningFleetStorageContribution($mined, $delivery, $declared, false);
    }

    /**
     * @param array<int, int> $mined
     */
    private function recipient(array $mined): MiningFleetStorageContribution
    {
        return new MiningFleetStorageContribution($mined, MiningFleetStorageDelivery::None, null, true);
    }
}
