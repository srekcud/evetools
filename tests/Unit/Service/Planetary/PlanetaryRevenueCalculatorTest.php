<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryRevenueCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryRevenueCalculator::class)]
#[Group('issue-1068')]
final class PlanetaryRevenueCalculatorTest extends TestCase
{
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int GENETICALLY_ENHANCED_LIVESTOCK = 15317;
    private const int WATER = 3645;
    private const int PLASMA_CRYSTAL_SCHEMATIC = 65;
    private const int COOLANT_SCHEMATIC = 66;
    private const int ROBOTICS_SCHEMATIC = 67;
    private const int WATER_SCHEMATIC = 68;
    private const string NOW = '2026-11-17T10:00:00+00:00';
    private const int DAYS = 3;

    private int $nextTransactionId = 1;
    private int $nextPinId = 1;

    public function testRealisedRevenueIsTheQuantityTimesThePriceOfThePiProductSalesOfThePeriodByTier(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report(
            [
                $this->sale($pilot, self::COOLANT, 10, 1_000.0, '2026-11-16T12:00:00+00:00'),
                $this->sale($pilot, self::COOLANT, 5, 2_000.0, '2026-11-17T08:00:00+00:00'),
                $this->sale($pilot, self::ROBOTICS, 2, 50_000.0, '2026-11-15T23:59:00+00:00'),
            ],
            [$pilot],
            [],
        );

        $realisedByTier = array_column($report['tiers'], 'realisedIsk', 'tier');

        $this->assertSame(['P1' => 0.0, 'P2' => 20_000.0, 'P3' => 100_000.0, 'P4' => 0.0], $realisedByTier);
        $this->assertSame(120_000.0, $report['realisedIsk']);
    }

    public function testAnItemThatIsNotTheOutputOfASchematicIsNotCounted(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report(
            [
                $this->sale($pilot, self::WATER, 1_000, 5.0, '2026-11-16T12:00:00+00:00'),
                $this->sale($pilot, self::GENETICALLY_ENHANCED_LIVESTOCK, 1, 7_000.0, '2026-11-16T12:00:00+00:00'),
            ],
            [$pilot],
            [],
        );

        $this->assertSame(0.0, $report['realisedIsk']);
    }

    public function testBuyTransactionsAndSalesOutsideThePeriodAreNotCounted(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $report = $this->report(
            [
                $this->sale($pilot, self::COOLANT, 1, 1_000.0, '2026-11-17T08:00:00+00:00'),
                $this->sale($pilot, self::COOLANT, 1, 4_000.0, '2026-11-13T23:59:59+00:00'),
                $this->sale($pilot, self::COOLANT, 1, 9_000.0, '2026-11-17T09:00:00+00:00', isBuy: true),
            ],
            [$pilot],
            [],
        );

        $this->assertSame(1_000.0, $report['realisedIsk']);
    }

    public function testDailyAmountsAndContributorsArePerDayAndPerCharacterSortedByValue(): void
    {
        $first = $this->character(2112000001, 'Pilot One');
        $second = $this->character(2112000002, 'Pilot Two');
        $report = $this->report(
            [
                $this->sale($first, self::COOLANT, 1, 1_000.0, '2026-11-16T09:00:00+00:00'),
                $this->sale($second, self::COOLANT, 1, 3_000.0, '2026-11-16T10:00:00+00:00'),
                $this->sale($first, self::COOLANT, 1, 500.0, '2026-11-17T09:00:00+00:00'),
            ],
            [$first, $second],
            [],
        );

        $this->assertSame(
            [['date' => '2026-11-16', 'amount' => 4_000.0], ['date' => '2026-11-17', 'amount' => 500.0]],
            $report['realisedDaily'],
        );
        $this->assertSame(
            [
                ['characterId' => 2112000002, 'characterName' => 'Pilot Two', 'value' => 3_000.0],
                ['characterId' => 2112000001, 'characterName' => 'Pilot One', 'value' => 1_500.0],
            ],
            $report['realisedContributors'],
        );
    }

    public function testTheChangeComparesThePeriodWithThePreviousOneAndIsUnknownWithoutPreviousSales(): void
    {
        $pilot = $this->character(2112000001, 'Pilot One');
        $withPrevious = $this->report(
            [
                $this->sale($pilot, self::COOLANT, 1, 1_500.0, '2026-11-17T08:00:00+00:00'),
                $this->sale($pilot, self::COOLANT, 1, 1_000.0, '2026-11-13T08:00:00+00:00'),
            ],
            [$pilot],
            [],
        );
        $withoutPrevious = $this->report(
            [$this->sale($pilot, self::COOLANT, 1, 1_500.0, '2026-11-17T08:00:00+00:00')],
            [$pilot],
            [],
        );

        $this->assertSame(50.0, $withPrevious['realisedChange']);
        $this->assertNull($withoutPrevious['realisedChange']);
        $this->assertNull(array_column($withoutPrevious['tiers'], 'change', 'tier')['P2']);
    }

    public function testTheEstimateStaysSeparateFromTheRealisedRevenueAndIsSplitByCharacter(): void
    {
        $first = $this->character(2112000001, 'Pilot One');
        $second = $this->character(2112000002, 'Pilot Two');
        $report = $this->report(
            [$this->sale($first, self::COOLANT, 1, 1_000.0, '2026-11-17T08:00:00+00:00')],
            [$first, $second],
            [$this->factoryColony($first, 40000001), $this->factoryColony($second, 40000002)],
            dailyIskByCharacter: [2112000001 => 10_000.0, 2112000002 => 30_000.0],
        );

        $this->assertSame(1_000.0, $report['realisedIsk']);
        $this->assertSame(40_000.0, $report['estimate']['dailyIsk']);
        $this->assertSame(120_000.0, $report['estimate']['periodIsk']);
        $this->assertSame(
            [
                ['characterId' => 2112000002, 'characterName' => 'Pilot Two', 'value' => 90_000.0],
                ['characterId' => 2112000001, 'characterName' => 'Pilot One', 'value' => 30_000.0],
            ],
            $report['estimate']['contributors'],
        );
    }

    public function testAProducingColonyOfACharacterWithoutAnySaleInThePeriodIsToFix(): void
    {
        $selling = $this->character(2112000001, 'Pilot One');
        $silent = $this->character(2112000002, 'Pilot Two');
        $report = $this->report(
            [$this->sale($selling, self::COOLANT, 1, 1_000.0, '2026-11-17T08:00:00+00:00')],
            [$selling, $silent],
            [$this->factoryColony($selling, 40000001), $this->factoryColony($silent, 40000002), $this->emptyColony($silent, 40000003)],
        );

        $this->assertSame(
            [['characterId' => 2112000002, 'characterName' => 'Pilot Two', 'planetId' => 40000002, 'planetName' => 'Planet 40000002', 'solarSystemName' => 'Jita']],
            $report['toFix'],
        );
    }

    public function testAnExpiredExtractorAloneDoesNotMakeAColonyProducing(): void
    {
        $silent = $this->character(2112000002, 'Pilot Two');
        $expiredExtractorColony = $this->colony($silent, 40000004, [
            (new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId(2848)->setExtractorProductTypeId(2268)->setExpiryTime(new \DateTimeImmutable('-1 day')),
        ]);
        $activeExtractorColony = $this->colony($silent, 40000005, [
            (new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId(2848)->setExtractorProductTypeId(2268)->setExpiryTime(new \DateTimeImmutable('+1 day')),
        ]);

        $report = $this->report([], [$silent], [$expiredExtractorColony, $activeExtractorColony]);

        $this->assertSame([40000005], array_column($report['toFix'], 'planetId'));
    }

    public function testThePeriodEndsToday(): void
    {
        $report = $this->report([], [], []);

        $this->assertSame(['2026-11-17', self::DAYS], [$report['periodEnd'], $report['days']]);
    }

    /**
     * @param CachedWalletTransaction[] $sales
     * @param Character[] $characters
     * @param PlanetaryColony[] $colonies
     * @param array<int, float> $dailyIskByCharacter
     * @return array<string, mixed>
     */
    private function report(array $sales, array $characters, array $colonies, array $dailyIskByCharacter = []): array
    {
        $user = new User();
        foreach ($characters as $character) {
            $user->addCharacter($character);
        }

        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findSalesByTypeIdsBetween')->willReturnCallback(
            static function (array $characters, array $typeIds, \DateTimeImmutable $from, \DateTimeImmutable $until) use ($sales): array {
                return array_values(array_filter(
                    $sales,
                    static fn (CachedWalletTransaction $sale): bool => in_array($sale->getTypeId(), $typeIds, true)
                        && !$sale->isBuy()
                        && $sale->getDate() >= $from
                        && $sale->getDate() < $until,
                ));
            },
        );
        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn($colonies);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            self::WATER_SCHEMATIC => ['inputs' => [], 'output' => ['typeId' => self::WATER, 'quantity' => 20]],
            self::COOLANT_SCHEMATIC => ['inputs' => [self::WATER => 40], 'output' => ['typeId' => self::COOLANT, 'quantity' => 5]],
            self::ROBOTICS_SCHEMATIC => ['inputs' => [self::COOLANT => 3], 'output' => ['typeId' => self::ROBOTICS, 'quantity' => 1]],
            self::PLASMA_CRYSTAL_SCHEMATIC => ['inputs' => [self::ROBOTICS => 1], 'output' => null],
        ]);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('findProductTier')->willReturnMap([
            [self::COOLANT, 'P2'],
            [self::ROBOTICS, 'P3'],
            [self::WATER, null],
        ]);
        $productionCalculator->method('calculateTotalDailyIsk')->willReturnCallback(
            static function (array $colonies) use ($dailyIskByCharacter): float {
                $total = 0.0;
                foreach ($colonies as $colony) {
                    $total += $dailyIskByCharacter[$colony->getCharacter()->getEveCharacterId()] ?? 0.0;
                }

                return $total;
            },
        );

        return (new PlanetaryRevenueCalculator($transactionRepository, $colonyRepository, $schematicTypeRepository, $productionCalculator))
            ->calculate($user, new \DateTimeImmutable(self::NOW), self::DAYS);
    }

    private function character(int $eveCharacterId, string $name): Character
    {
        return (new Character())->setEveCharacterId($eveCharacterId)->setName($name);
    }

    private function sale(Character $character, int $typeId, int $quantity, float $unitPrice, string $date, bool $isBuy = false): CachedWalletTransaction
    {
        return (new CachedWalletTransaction())
            ->setCharacter($character)
            ->setTransactionId($this->nextTransactionId++)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setIsBuy($isBuy)
            ->setLocationId(60003760)
            ->setDate(new \DateTimeImmutable($date));
    }

    private function factoryColony(Character $character, int $planetId): PlanetaryColony
    {
        return $this->colony($character, $planetId, [
            (new PlanetaryPin())->setPinId($this->nextPinId++)->setTypeId(2473)->setSchematicId(self::COOLANT_SCHEMATIC),
        ]);
    }

    private function emptyColony(Character $character, int $planetId): PlanetaryColony
    {
        return $this->colony($character, $planetId, []);
    }

    /**
     * @param PlanetaryPin[] $pins
     */
    private function colony(Character $character, int $planetId, array $pins): PlanetaryColony
    {
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetName('Planet '.$planetId)
            ->setPlanetType('barren')
            ->setSolarSystemId(30000142)
            ->setSolarSystemName('Jita')
            ->setUpgradeLevel(4)
            ->setNumPins(count($pins))
            ->setLastUpdate(new \DateTimeImmutable('2026-11-17T09:00:00Z'));
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }
}
