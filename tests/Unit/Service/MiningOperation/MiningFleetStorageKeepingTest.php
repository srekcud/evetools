<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningFleetStorageDelivery;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningFleetStorageKeeping;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningFleetStorageKeeping::class)]
#[Group('issue-266')]
final class MiningFleetStorageKeepingTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const int MERCOXIT = 11396;
    private const float VELDSPAR_BASE_PRICE = 19.61;
    private const float BITUMENS_BASE_PRICE = 221.45;
    private const float MERCOXIT_BASE_PRICE = 17_250.0;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
    }

    public function testParticipantWhoKeepsEverythingKeepsHisMinedValueAndDeliversNothing(): void
    {
        self::assertSame(['keptValue' => 2_699_093, 'deliveredValue' => 0], $this->keepingOfAria(MiningFleetStorageDelivery::None, null));
    }

    public function testParticipantWhoDeliversEverythingKeepsNothingAndDeliversHisMinedValue(): void
    {
        self::assertSame(['keptValue' => 0, 'deliveredValue' => 2_699_093], $this->keepingOfAria(MiningFleetStorageDelivery::All, null));
    }

    public function testDeliveringAllTheVeldsparKeepsTheValueOfTheBitumensRoundedOnce(): void
    {
        self::assertSame(
            ['keptValue' => 738_093, 'deliveredValue' => 1_961_000],
            $this->keepingOfAria(MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 100_000]),
        );
    }

    public function testDeliveringPartOfTheVeldsparKeepsTheRestOfItAndTheBitumensAndDeliversTheDifference(): void
    {
        self::assertSame(
            ['keptValue' => 2_502_993, 'deliveredValue' => 196_100],
            $this->keepingOfAria(MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 10_000]),
        );
    }

    public function testDeliveringMoreVeldsparThanMinedLeavesTheKeptAndDeliveredValuesUnknown(): void
    {
        self::assertSame(
            ['keptValue' => null, 'deliveredValue' => null],
            $this->keepingOfAria(MiningFleetStorageDelivery::Partial, [self::VELDSPAR => 100_001]),
        );
    }

    public function testDeliveringATypeThatWasNotMinedLeavesTheKeptAndDeliveredValuesUnknown(): void
    {
        self::assertSame(
            ['keptValue' => null, 'deliveredValue' => null],
            $this->keepingOfAria(MiningFleetStorageDelivery::Partial, [self::MERCOXIT => 5]),
        );
    }

    public function testDeliveringEverythingWithAnUnknownMinedValueLeavesTheKeptAndDeliveredValuesUnknown(): void
    {
        $operation = $this->e1Operation(unreadStarts: ['Brann II']);

        self::assertSame(
            ['keptValue' => null, 'deliveredValue' => null],
            $this->keepingOf($operation, $this->brann, null, MiningFleetStorageDelivery::All, null),
        );
    }

    private function keepingOfAria(MiningFleetStorageDelivery $delivery, ?array $deliveredQuantities): array
    {
        return $this->keepingOf($this->e1Operation(), $this->aria, self::ARIA_MINED_VALUE, $delivery, $deliveredQuantities);
    }

    private function keepingOf(MiningOperation $operation, User $pilot, ?int $minedValue, MiningFleetStorageDelivery $delivery, ?array $deliveredQuantities): array
    {
        $keeping = MiningFleetStorageKeeping::of(
            (new MinedQuantityCalculator())->ofParticipant($this->membershipOf($operation, $pilot)),
            $minedValue,
            $delivery,
            $deliveredQuantities,
            [self::VELDSPAR => self::VELDSPAR_BASE_PRICE, self::BITUMENS => self::BITUMENS_BASE_PRICE, self::MERCOXIT => self::MERCOXIT_BASE_PRICE],
        );

        return ['keptValue' => $keeping->keptValue, 'deliveredValue' => $keeping->deliveredValue];
    }
}
