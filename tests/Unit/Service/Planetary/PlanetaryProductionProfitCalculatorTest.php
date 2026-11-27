<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Dto\PlanetaryTaxDay;
use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Planetary\PlanetaryPaidPriceCalculator;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryProductionProfitCalculator;
use App\Service\StructureMarketService;
use App\Service\Sync\WalletReadingStateReader;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryProductionProfitCalculator::class)]
#[Group('issue-1400')]
final class PlanetaryProductionProfitCalculatorTest extends TestCase
{
    private const int EXTRACTED_P0 = 2268;
    private const int BOUGHT_P1 = 2389;
    private const int MADE_P1 = 2390;
    private const int P2 = 9832;
    private const int P3 = 9848;
    private const int DEFAULT_HUB = 1049588174021;
    private const int OWN_HUB = 1035466617946;
    private const string NOW = '2026-11-26T10:00:00+00:00';
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    private int $nextTransactionId = 1;

    public function testASoldProductIsValuedAtTheHubWithItsInputsAtThePaidPriceAndTheExtractedOnesAtTheHubBuyPrice(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 10_000.0, 0.0, 10_000.0, [[self::BOUGHT_P1, 20_000.0], [self::EXTRACTED_P0, 2_000.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 5_000.0, 2_000.0, 3_000.0, []),
            ],
            purchases: [[self::BOUGHT_P1, 10, 150.0]],
            hubSell: [self::P2 => 700.0],
            hubBuy: [self::EXTRACTED_P0 => 40.0],
        );

        $line = $this->lineOf($report, self::P2);

        $this->assertSame(
            [10_000.0, false, 700.0, 7_000_000.0, 3_080_000.0, 3_920_000.0, 56.0, true],
            [$line['soldQuantity'], $line['internal'], $line['hubSellPrice'], $line['salesIsk'], $line['inputsIsk'], $line['profitPerDay'], $line['marginPercent'], $line['valuesExtractedAtHubBuy']],
        );
        $this->assertSame([7_000_000.0, 3_080_000.0, 3_920_000.0, 0], [$report['salesIsk'], $report['inputsIsk'], $report['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testTheExtractedOutputIsNotAProductLine(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::EXTRACTED_P0, 100.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 500.0, 100.0, 400.0, []),
            ],
            hubSell: [self::P2 => 10.0],
            hubBuy: [self::EXTRACTED_P0 => 1.0],
        );

        $this->assertSame([self::P2], array_column($report['lines'], 'typeId'));
    }

    public function testAnInputNeverBoughtMakesTheLineUnknownNotZero(): void
    {
        $report = $this->report(
            items: [$this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::BOUGHT_P1, 300.0]])],
            hubSell: [self::P2 => 700.0],
        );

        $line = $this->lineOf($report, self::P2);

        $this->assertSame([70_000.0, null, null, null], [$line['salesIsk'], $line['inputsIsk'], $line['profitPerDay'], $line['marginPercent']]);
        $this->assertSame([0.0, 0.0, 0.0, 1], [$report['salesIsk'], $report['inputsIsk'], $report['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testAnExtractedInputWithoutBuyOrderAtTheHubMakesTheLineUnknown(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::EXTRACTED_P0, 100.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 500.0, 100.0, 400.0, []),
            ],
            hubSell: [self::P2 => 10.0],
        );

        $this->assertSame([null, 1], [$this->lineOf($report, self::P2)['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testAPartNotBoughtDoesNotNeedAPaidPrice(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::EXTRACTED_P0, 100.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 100.0, 100.0, 0.0, []),
            ],
            hubSell: [self::P2 => 10.0],
            hubBuy: [self::EXTRACTED_P0 => 1.0],
        );

        $this->assertSame([1_000.0, 100.0, 900.0], [
            $this->lineOf($report, self::P2)['salesIsk'],
            $this->lineOf($report, self::P2)['inputsIsk'],
            $this->lineOf($report, self::P2)['profitPerDay'],
        ]);
    }

    public function testAnInternalStepHasNoMarginOfItsOwnAndItsCostIsCountedOnTheFinalProduct(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::MADE_P1, 200.0]]),
                $this->item(self::MADE_P1, 'P1', 200.0, 200.0, 0.0, [[self::EXTRACTED_P0, 400.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 500.0, 400.0, 100.0, []),
            ],
            hubSell: [self::P2 => 50.0],
            hubBuy: [self::EXTRACTED_P0 => 2.0],
        );

        $internal = $this->lineOf($report, self::MADE_P1);
        $final = $this->lineOf($report, self::P2);

        $this->assertSame([true, 0.0, null, null], [$internal['internal'], $internal['salesIsk'], $internal['profitPerDay'], $internal['marginPercent']]);
        $this->assertSame([5_000.0, 800.0, 4_200.0, true], [$final['salesIsk'], $final['inputsIsk'], $final['profitPerDay'], $final['valuesExtractedAtHubBuy']]);
        $this->assertSame([4_200.0, 0], [$report['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testAnInternalStepCarriesTheMarginItWouldHaveIfSoldWithoutChangingTheTotals(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::MADE_P1, 200.0]]),
                $this->item(self::MADE_P1, 'P1', 200.0, 200.0, 0.0, [[self::EXTRACTED_P0, 400.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 500.0, 400.0, 100.0, []),
            ],
            hubSell: [self::P2 => 50.0, self::MADE_P1 => 10.0],
            hubBuy: [self::EXTRACTED_P0 => 2.0],
        );

        $ifSold = $this->lineOf($report, self::MADE_P1)['ifSold'];

        $this->assertSame([2_000.0, 800.0, 1_200.0, 60.0], [$ifSold['salesIsk'], $ifSold['inputsIsk'], $ifSold['profitPerDay'], $ifSold['marginPercent']]);
        $this->assertSame([4_200.0, 0], [$report['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testAnInternalStepWithoutHubPriceOrWithAnUnknownInputHasAnUnknownMarginIfSoldNeverZero(): void
    {
        $withoutPrice = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::MADE_P1, 200.0]]),
                $this->item(self::MADE_P1, 'P1', 200.0, 200.0, 0.0, [[self::EXTRACTED_P0, 400.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 500.0, 400.0, 100.0, []),
            ],
            hubSell: [self::P2 => 50.0],
            hubBuy: [self::EXTRACTED_P0 => 2.0],
        );
        $withoutInput = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::MADE_P1, 200.0]]),
                $this->item(self::MADE_P1, 'P1', 200.0, 200.0, 0.0, [[self::BOUGHT_P1, 400.0]]),
            ],
            hubSell: [self::P2 => 50.0, self::MADE_P1 => 10.0],
        );

        $noPrice = $this->lineOf($withoutPrice, self::MADE_P1)['ifSold'];
        $noInput = $this->lineOf($withoutInput, self::MADE_P1)['ifSold'];

        $this->assertSame([null, 800.0, null, null], [$noPrice['salesIsk'], $noPrice['inputsIsk'], $noPrice['profitPerDay'], $noPrice['marginPercent']]);
        $this->assertSame([2_000.0, null, null, null], [$noInput['salesIsk'], $noInput['inputsIsk'], $noInput['profitPerDay'], $noInput['marginPercent']]);
    }

    public function testASoldProductHasNoMarginIfSold(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::EXTRACTED_P0, 200.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 500.0, 200.0, 300.0, []),
            ],
            hubSell: [self::P2 => 50.0],
            hubBuy: [self::EXTRACTED_P0 => 2.0],
        );

        $this->assertNull($this->lineOf($report, self::P2)['ifSold']);
    }

    public function testAnInternalStepWithAnUnknownInputMakesTheFinalProductUnknownButIsNotCountedItself(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::MADE_P1, 200.0]]),
                $this->item(self::MADE_P1, 'P1', 200.0, 200.0, 0.0, [[self::BOUGHT_P1, 400.0]]),
            ],
            hubSell: [self::P2 => 50.0],
        );

        $this->assertSame([null, 1], [$this->lineOf($report, self::P2)['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testTheConsumptionAboveTheOwnOutputIsBoughtAtThePaidPriceAndTheRestIsMadeByTheChain(): void
    {
        $report = $this->report(
            items: [
                $this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::MADE_P1, 300.0]]),
                $this->item(self::MADE_P1, 'P1', 100.0, 100.0, 0.0, [[self::EXTRACTED_P0, 100.0]]),
                $this->item(self::EXTRACTED_P0, 'P0', 100.0, 100.0, 0.0, []),
            ],
            purchases: [[self::MADE_P1, 10, 30.0]],
            hubSell: [self::P2 => 100.0],
            hubBuy: [self::EXTRACTED_P0 => 3.0],
        );

        $this->assertSame([10_000.0, 6_300.0, 3_700.0], [
            $this->lineOf($report, self::P2)['salesIsk'],
            $this->lineOf($report, self::P2)['inputsIsk'],
            $this->lineOf($report, self::P2)['profitPerDay'],
        ]);
    }

    public function testASoldProductWithoutHubPriceIsUnknownAndNeverZero(): void
    {
        $report = $this->report(
            items: [$this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [[self::BOUGHT_P1, 100.0]])],
            purchases: [[self::BOUGHT_P1, 10, 5.0]],
        );

        $line = $this->lineOf($report, self::P2);

        $this->assertSame([null, null, null, 1], [$line['hubSellPrice'], $line['salesIsk'], $line['profitPerDay'], $report['unknownLineCount']]);
    }

    public function testTheTheoreticalJitaRevenueStaysOnTheLine(): void
    {
        $report = $this->report(
            items: [$this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [], unitPrice: 800.0, dailyIskValue: 80_000.0)],
            hubSell: [self::P2 => 700.0],
        );

        $this->assertSame(80_000.0, $this->lineOf($report, self::P2)['jitaIsk']);
    }

    public function testTheHubIsThePreferredMarketOfTheUserElseTheDefaultOne(): void
    {
        $withPreference = $this->report(items: [], preferredStructureId: self::OWN_HUB, preferredStructureName: 'Own Hub', hubHasData: true);
        $withoutPreference = $this->report(items: [], hubHasData: false);

        $this->assertSame(['structureId' => self::OWN_HUB, 'structureName' => 'Own Hub', 'hasData' => true], $withPreference['hub']);
        $this->assertSame(['structureId' => self::DEFAULT_HUB, 'structureName' => 'Default Hub', 'hasData' => false], $withoutPreference['hub']);
    }

    public function testThePocoTaxesOfTheLastThirtyDaysAreSpreadPerDayAndDeductedFromTheNetProfit(): void
    {
        $report = $this->report(
            items: [$this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [], unitPrice: 1.0, dailyIskValue: 1.0)],
            hubSell: [self::P2 => 100.0],
            taxes: [
                new PlanetaryTaxDay(2112000001, 40000001, '2026-11-20', 60_000.0, 0.0),
                new PlanetaryTaxDay(2112000001, 40000002, '2026-11-21', 0.0, 30_000.0),
            ],
        );

        $this->assertSame([30, 3_000.0, 7_000.0, 10_000.0], [$report['taxDays'], $report['taxesPerDay'], $report['netProfitPerDay'], $report['profitPerDay']]);
    }

    public function testTheTaxesAreReadFromThirtyDaysBeforeNow(): void
    {
        $sinceSeen = null;
        $this->report(items: [], taxSince: $sinceSeen);

        $this->assertSame('2026-10-27T10:00:00+00:00', $sinceSeen?->format(\DATE_ATOM));
    }

    public function testWithoutAnyTaxLineTheTaxesAreUnknownAndNotDeducted(): void
    {
        $report = $this->report(
            items: [$this->item(self::P2, 'P2', 100.0, 0.0, 100.0, [])],
            hubSell: [self::P2 => 100.0],
        );

        $this->assertSame([null, 10_000.0], [$report['taxesPerDay'], $report['netProfitPerDay']]);
    }

    public function testAnAccountWithoutProductionHasNoLine(): void
    {
        $report = $this->report(items: []);

        $this->assertSame([[], 0.0, 0.0, 0.0, 0, null], [$report['lines'], $report['salesIsk'], $report['inputsIsk'], $report['profitPerDay'], $report['unknownLineCount'], $report['taxesPerDay']]);
    }

    public function testAFactoryThatRunsAtZeroProducesNoLine(): void
    {
        $report = $this->report(items: [$this->item(self::P2, 'P2', 0.0, 0.0, 0.0, [])], hubSell: [self::P2 => 10.0]);

        $this->assertSame([], $report['lines']);
    }

    public function testTheWalletsNotReadAreReported(): void
    {
        $report = $this->report(items: [], unreadWalletCharacterIds: [2112000001]);

        $this->assertSame(
            [['characterId' => 2112000001, 'characterName' => 'Pilot One', 'status' => 'never_read']],
            $report['unsyncedWallets'],
        );
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function lineOf(array $report, int $typeId): array
    {
        foreach ($report['lines'] as $line) {
            if ($line['typeId'] === $typeId) {
                return $line;
            }
        }

        $this->fail(sprintf('No line for type %d', $typeId));
    }

    /**
     * @param list<array{int, float}> $inputs
     * @return array<string, mixed>
     */
    private function item(int $typeId, string $tier, float $dailyQuantity, float $consumedInternally, float $sellable, array $inputs, ?float $unitPrice = null, float $dailyIskValue = 0.0): array
    {
        return [
            'tier' => $tier,
            'typeId' => $typeId,
            'typeName' => 'Type '.$typeId,
            'dailyQuantity' => $dailyQuantity,
            'dailyConsumedInternally' => $consumedInternally,
            'dailySellableQuantity' => $sellable,
            'unitPrice' => $unitPrice,
            'dailyIskValue' => $dailyIskValue,
            'inputs' => array_map(
                static fn (array $input): array => ['typeId' => $input[0], 'typeName' => 'Type '.$input[0], 'dailyConsumed' => $input[1], 'dailyProduced' => 0.0, 'delta' => 0.0],
                $inputs,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array{int, int, float}> $purchases typeId, quantity, unit price
     * @param array<int, float> $hubSell
     * @param array<int, float> $hubBuy
     * @param list<PlanetaryTaxDay> $taxes
     * @param list<int> $unreadWalletCharacterIds
     * @return array<string, mixed>
     */
    private function report(
        array $items,
        array $purchases = [],
        array $hubSell = [],
        array $hubBuy = [],
        array $taxes = [],
        ?int $preferredStructureId = null,
        ?string $preferredStructureName = null,
        bool $hubHasData = true,
        ?\DateTimeImmutable &$taxSince = null,
        array $unreadWalletCharacterIds = [],
    ): array {
        $pilot = (new Character())
            ->setEveCharacterId(2112000001)
            ->setName('Pilot One')
            ->setEveToken((new EveToken())->setScopes([self::WALLET_SCOPE]));
        $user = new User();
        $user->addCharacter($pilot);
        if ($preferredStructureId !== null) {
            $user->setPreferredMarketStructureId($preferredStructureId)->setPreferredMarketStructureName($preferredStructureName);
        }

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn([new PlanetaryColony()]);

        $tiers = [];
        foreach ($items as $item) {
            $tiers[$item['tier']]['tier'] = $item['tier'];
            $tiers[$item['tier']]['items'][] = $item;
        }
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateProduction')->willReturn(['tiers' => array_values($tiers), 'totalDailyIsk' => 0.0, 'totalMonthlyIsk' => 0.0]);

        $purchased = array_map(
            fn (array $purchase): CachedWalletTransaction => (new CachedWalletTransaction())
                ->setCharacter($pilot)
                ->setTransactionId($this->nextTransactionId++)
                ->setTypeId($purchase[0])
                ->setQuantity($purchase[1])
                ->setUnitPrice($purchase[2])
                ->setIsBuy(true)
                ->setLocationId(60003760)
                ->setDate(new \DateTimeImmutable('2026-11-20T12:00:00+00:00')),
            $purchases,
        );
        $transactionRepository = $this->createStub(CachedWalletTransactionRepository::class);
        $transactionRepository->method('findBuysByCharactersSince')->willReturnCallback(
            static fn (array $characters, array $typeIds): array => array_values(array_filter(
                $purchased,
                static fn (CachedWalletTransaction $purchase): bool => in_array($purchase->getTypeId(), $typeIds, true),
            )),
        );
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([
            1 => ['inputs' => [self::BOUGHT_P1 => 1, self::MADE_P1 => 1, self::EXTRACTED_P0 => 1], 'output' => ['typeId' => self::P2, 'quantity' => 1]],
        ]);
        $readings = $unreadWalletCharacterIds === [] ? [(new CharacterWalletReading())
            ->setCharacter($pilot)
            ->setReadAt(new \DateTimeImmutable('2026-11-26T09:30:00+00:00'))
            ->setNextReadAt(new \DateTimeImmutable('2026-11-26T10:30:00+00:00'))] : [];
        $readingRepository = $this->createStub(CharacterWalletReadingRepository::class);
        $readingRepository->method('findByCharacters')->willReturn($readings);
        $paidPriceCalculator = new PlanetaryPaidPriceCalculator(
            $transactionRepository,
            $schematicTypeRepository,
            $this->createStub(TypeNameResolver::class),
            new WalletReadingStateReader($readingRepository),
        );

        $expectedHub = $preferredStructureId ?? self::DEFAULT_HUB;
        $market = $this->createStub(StructureMarketService::class);
        $market->method('getLowestSellPrices')->willReturnCallback(
            static fn (int $structureId, array $typeIds): array => $structureId === $expectedHub
                ? array_combine($typeIds, array_map(static fn (int $typeId): ?float => $hubSell[$typeId] ?? null, $typeIds))
                : [],
        );
        $market->method('getHighestBuyPrices')->willReturnCallback(
            static fn (int $structureId, array $typeIds): array => $structureId === $expectedHub
                ? array_combine($typeIds, array_map(static fn (int $typeId): ?float => $hubBuy[$typeId] ?? null, $typeIds))
                : [],
        );
        $market->method('hasCachedData')->willReturnCallback(static fn (int $structureId): bool => $structureId === $expectedHub && $hubHasData);

        $journalRepository = $this->createStub(CachedWalletJournalEntryRepository::class);
        $journalRepository->method('planetaryTaxesByColonyAndDay')->willReturnCallback(
            static function (array $characters, \DateTimeImmutable $since) use ($taxes, &$taxSince): array {
                $taxSince = $since;

                return $taxes;
            },
        );

        return (new PlanetaryProductionProfitCalculator(
            $colonyRepository,
            $productionCalculator,
            $paidPriceCalculator,
            $market,
            $journalRepository,
            self::DEFAULT_HUB,
            'Default Hub',
        ))->calculate($user, new \DateTimeImmutable(self::NOW));
    }
}
