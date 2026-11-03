<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MarketSide;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\MiningCharacterValuation;
use App\Service\MiningOperation\MiningOperationValuation;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\MiningOperation\MiningParticipantValuation;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MiningOperationValuator::class)]
#[CoversClass(MiningOperationValuation::class)]
#[CoversClass(MiningParticipantValuation::class)]
#[CoversClass(MiningCharacterValuation::class)]
#[Group('issue-264')]
final class MiningOperationValuatorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string READ_AT = '2026-11-05T21:50:00+00:00';
    private const array E1_SHARES = [
        'Aria Solberg' => ['state' => 'known', 'minedValue' => self::ARIA_MINED_VALUE, 'share' => 2_429_184, 'roundingAdjustment' => 1],
        'Brann Holt' => ['state' => 'known', 'minedValue' => self::BRANN_MINED_VALUE, 'share' => 2_581_614, 'roundingAdjustment' => 0],
        'Cyra Lune' => ['state' => 'known', 'minedValue' => self::CYRA_MINED_VALUE, 'share' => 158, 'roundingAdjustment' => 0],
    ];
    private const array E1_TOTALS = [
        'totalValue' => self::E1_TOTAL_VALUE,
        'boosterCut' => 556_773,
        'corporationCut' => 0,
        'roundingRemainder' => 2,
        'freezeBlockers' => [],
    ];
    private const array UNKNOWN_TOTALS = [
        'totalValue' => null,
        'boosterCut' => null,
        'corporationCut' => null,
        'roundingRemainder' => null,
    ];

    private MockClock $clock;
    private MiningOperationValuator $valuator;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->clock = new MockClock(self::READ_AT);
        $this->valuator = $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock);
    }

    public function testE1IsValuedOnTheCompressedJitaSellPricesAndSplitWithATenPercentBoosterCut(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation()));

        self::assertSame(
            ['participants' => self::E1_SHARES, 'totals' => self::E1_TOTALS],
            ['participants' => $this->participantValuesOf($valuation), 'totals' => $this->totalsOf($valuation)],
        );
    }

    public function testPreviewReadsTheCachePricesAtTheClockTimeWithTheirJitaSyncTime(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation()));

        $unitPrices = $valuation->unitPricesByTypeId;
        ksort($unitPrices);
        self::assertSame(
            [
                'frozen' => false,
                'pricesReadAt' => self::READ_AT,
                'pricesSyncedAt' => self::JITA_SYNCED_AT,
                'unitPrices' => [self::VELDSPAR => 19.61, self::BITUMENS => 221.45],
                'missingPriceTypeIds' => [],
            ],
            [
                'frozen' => $valuation->frozen,
                'pricesReadAt' => $valuation->pricesReadAt->format(\DateTimeInterface::ATOM),
                'pricesSyncedAt' => $valuation->pricesSyncedAt?->format(\DateTimeInterface::ATOM),
                'unitPrices' => $unitPrices,
                'missingPriceTypeIds' => $valuation->missingPriceTypeIds,
            ],
        );
    }

    public function testEachCharacterIsValuedOnItsOwnQuantitiesForDisplay(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation()));

        self::assertSame(
            [
                'Aria Solberg' => ['Aria II' => 738_093, 'Aria Solberg' => 1_961_000],
                'Brann Holt' => ['Brann Holt' => 2_214_721, 'Brann II' => 653_739],
                'Cyra Lune' => ['Cyra Lune' => 176],
            ],
            $this->characterValuesOf($valuation),
        );
    }

    public function testUnreadStartSnapshotOfBrannIIMakesBrannAndEveryShareUnknownWhileTheOthersKeepTheirValue(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Brann II'])));

        self::assertSame(
            [
                'participants' => [
                    'Aria Solberg' => ['state' => 'known', 'minedValue' => self::ARIA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                    'Brann Holt' => ['state' => 'unknown', 'minedValue' => null, 'share' => null, 'roundingAdjustment' => null],
                    'Cyra Lune' => ['state' => 'known', 'minedValue' => self::CYRA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                ],
                'totals' => self::UNKNOWN_TOTALS + ['freezeBlockers' => ['quantities_not_known']],
            ],
            ['participants' => $this->participantValuesOf($valuation), 'totals' => $this->totalsOf($valuation)],
        );
    }

    public function testCorrectingTheUnknownQuantityOfBrannIIGivesBackTheSharesOfE1(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Brann II']));
        $this->enrolmentOf('Brann II')->correct(self::VELDSPAR, null, 33_337, $this->aria, new \DateTimeImmutable('2026-11-05T21:45:00+00:00'));

        $valuation = $this->valuator->value($operation);

        self::assertSame(
            ['participants' => self::E1_SHARES, 'totals' => self::E1_TOTALS],
            ['participants' => $this->participantValuesOf($valuation), 'totals' => $this->totalsOf($valuation)],
        );
    }

    public function testParticipantValueIsRoundedOnceOverAllItsCharacters(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(endLedgers: [
            'Brann II' => [$this->entry('2026-11-05', self::BITUMENS, self::S1, 1)],
        ]));

        $valuation = $this->valuator->value($operation);

        self::assertSame(
            ['participant' => 2_214_943, 'characters' => ['Brann Holt' => 2_214_721, 'Brann II' => 221]],
            ['participant' => $this->participantValuesOf($valuation)['Brann Holt']['minedValue'] ?? null, 'characters' => $this->characterValuesOf($valuation)['Brann Holt'] ?? null],
        );
    }

    public function testOreWithoutPriceOnTheBasisMakesItsMinersUnknownAndIsListedAsMissing(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::COMPRESSED_BITUMENS]);
        $valuator = $this->valuatorOn($this->jitaMarketSyncedAt($jitaSellPrices), $this->clock);

        $valuation = $valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation()));

        $unitPrices = $valuation->unitPricesByTypeId;
        ksort($unitPrices);
        self::assertSame(
            [
                'participants' => [
                    'Aria Solberg' => ['state' => 'known', 'minedValue' => null, 'share' => null, 'roundingAdjustment' => null],
                    'Brann Holt' => ['state' => 'known', 'minedValue' => null, 'share' => null, 'roundingAdjustment' => null],
                    'Cyra Lune' => ['state' => 'known', 'minedValue' => self::CYRA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                ],
                'totals' => self::UNKNOWN_TOTALS + ['freezeBlockers' => ['prices_missing']],
                'unitPrices' => [self::VELDSPAR => 19.61, self::BITUMENS => null],
                'missingPriceTypeIds' => [self::BITUMENS],
            ],
            [
                'participants' => $this->participantValuesOf($valuation),
                'totals' => $this->totalsOf($valuation),
                'unitPrices' => $unitPrices,
                'missingPriceTypeIds' => $valuation->missingPriceTypeIds,
            ],
        );
    }

    public function testBoosterWhoMinedNothingIsAKnownParticipantWorthZero(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(endLedgers: [
            'Cyra Lune' => [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 2)],
        ]));

        $valuation = $this->valuator->value($operation);

        self::assertSame(
            [['state' => 'known', 'minedValue' => 0], 5_567_553],
            [
                array_intersect_key($this->participantValuesOf($valuation)['Cyra Lune'] ?? [], ['state' => true, 'minedValue' => true]),
                $valuation->totalValue,
            ],
        );
    }

    public function testParticipantWhoseEndSnapshotIsStillWaitingIsMeasuringAndBlocksTheFreeze(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation(waitingEnds: ['Aria II'])));

        self::assertSame(
            [['state' => 'measuring', 'minedValue' => null], null, ['quantities_not_known']],
            [
                array_intersect_key($this->participantValuesOf($valuation)['Aria Solberg'] ?? [], ['state' => true, 'minedValue' => true]),
                $valuation->totalValue,
                $this->totalsOf($valuation)['freezeBlockers'],
            ],
        );
    }

    public function testIskPerHourRunsFromTheStartSnapshotToTheDepartureOrTheEndOfTheOperation(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation()));

        self::assertSame(
            [
                'participants' => [
                    'Aria Solberg' => ['durationSeconds' => 5_400, 'iskPerHour' => 1_799_395.33, 'valuePercent' => 48.4774],
                    'Brann Holt' => ['durationSeconds' => 3_900, 'iskPerHour' => 2_647_809.23, 'valuePercent' => 51.5194],
                    'Cyra Lune' => ['durationSeconds' => 5_400, 'iskPerHour' => 117.33, 'valuePercent' => 0.0032],
                ],
                'operation' => ['durationSeconds' => 5_400, 'iskPerHour' => 3_711_819.33],
            ],
            [
                'participants' => $this->participantRatesOf($valuation),
                'operation' => ['durationSeconds' => $valuation->durationSeconds, 'iskPerHour' => $valuation->iskPerHour === null ? null : round($valuation->iskPerHour, 2)],
            ],
        );
    }

    public function testUnknownValueOfBrannLeavesHisIskPerHourTheOperationIskPerHourAndEveryPercentUnknown(): void
    {
        $valuation = $this->valuator->value($this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Brann II'])));

        self::assertSame(
            [
                'participants' => [
                    'Aria Solberg' => ['durationSeconds' => 5_400, 'iskPerHour' => 1_799_395.33, 'valuePercent' => null],
                    'Brann Holt' => ['durationSeconds' => 3_900, 'iskPerHour' => null, 'valuePercent' => null],
                    'Cyra Lune' => ['durationSeconds' => 5_400, 'iskPerHour' => 117.33, 'valuePercent' => null],
                ],
                'operationIskPerHour' => null,
            ],
            ['participants' => $this->participantRatesOf($valuation), 'operationIskPerHour' => $valuation->iskPerHour],
        );
    }

    public function testParticipantWithoutAnyTakenStartSnapshotKeepsHisCorrectedValueWithoutDurationNorIskPerHour(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation(unreadStarts: ['Cyra Lune']));
        $this->enrolmentOf('Cyra Lune')->correct(self::VELDSPAR, null, 9, $this->aria, new \DateTimeImmutable('2026-11-05T21:45:00+00:00'));

        $valuation = $this->valuator->value($operation);

        self::assertSame(
            [self::CYRA_MINED_VALUE, ['durationSeconds' => null, 'iskPerHour' => null, 'valuePercent' => 0.0032]],
            [$this->participantValuesOf($valuation)['Cyra Lune']['minedValue'] ?? null, $this->participantRatesOf($valuation)['Cyra Lune'] ?? null],
        );
    }

    public function testEachForThemselvesOperationShowsMinedValuesAndIskPerHourWithoutAnyShare(): void
    {
        $valuation = $this->valuator->value($this->eachForThemselvesE1Operation());

        self::assertSame(
            [
                'participants' => [
                    'Aria Solberg' => ['state' => 'known', 'minedValue' => self::ARIA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                    'Brann Holt' => ['state' => 'known', 'minedValue' => self::BRANN_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                    'Cyra Lune' => ['state' => 'known', 'minedValue' => self::CYRA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                ],
                'totals' => ['totalValue' => self::E1_TOTAL_VALUE, 'boosterCut' => null, 'corporationCut' => null, 'roundingRemainder' => null, 'freezeBlockers' => ['each_for_themselves']],
                'rates' => [
                    'Aria Solberg' => ['durationSeconds' => 5_400, 'iskPerHour' => 1_799_395.33, 'valuePercent' => null],
                    'Brann Holt' => ['durationSeconds' => 3_900, 'iskPerHour' => 2_647_809.23, 'valuePercent' => null],
                    'Cyra Lune' => ['durationSeconds' => 5_400, 'iskPerHour' => 117.33, 'valuePercent' => null],
                ],
                'operationIskPerHour' => 3_711_819.33,
            ],
            [
                'participants' => $this->participantValuesOf($valuation),
                'totals' => $this->totalsOf($valuation),
                'rates' => $this->participantRatesOf($valuation),
                'operationIskPerHour' => $valuation->iskPerHour === null ? null : round($valuation->iskPerHour, 2),
            ],
        );
    }

    public function testEachForThemselvesPricesAreReadAgainOnEveryReading(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $valuatorAfterTheSync = $this->valuatorOn($this->jitaMarketSyncedAt([self::COMPRESSED_VELDSPAR => 25.0] + $this->jitaSellPrices()), $this->clock);

        self::assertSame(
            [self::E1_TOTAL_VALUE, 25.0],
            [$this->valuator->value($operation)->totalValue, $valuatorAfterTheSync->value($operation)->unitPricesByTypeId[self::VELDSPAR] ?? null],
        );
    }

    public function testBoosterCutWithoutBoosterIsPreviewedButBlocksTheFreeze(): void
    {
        $operation = $this->e1Operation();
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10);

        self::assertSame(array_merge(self::E1_TOTALS, ['freezeBlockers' => ['booster_missing']]), $this->totalsOf($this->valuator->value($operation)));
    }

    public function testOperationInProgressIsNotClosingAndItsQuantitiesAreStillMeasured(): void
    {
        $operation = $this->startedSpodumainOperation();
        $this->takenStartSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 5_000)]);

        self::assertSame(['not_closing', 'quantities_not_known'], $this->totalsOf($this->valuator->value($operation))['freezeBlockers']);
    }

    public function testFrozenSharesKeepTheirPricesAndSharesAfterTheNextJitaSync(): void
    {
        $operation = $this->frozenE1Operation();
        $valuatorAfterTheSync = $this->valuatorOn($this->jitaMarketSyncedAt([self::COMPRESSED_VELDSPAR => 25.0] + $this->jitaSellPrices(), [], '2026-11-05T22:00:00+00:00'), new MockClock('2026-11-05T22:10:00+00:00'));

        $valuation = $valuatorAfterTheSync->value($operation);

        $unitPrices = $valuation->unitPricesByTypeId;
        ksort($unitPrices);
        self::assertSame(
            [
                'participants' => self::E1_SHARES,
                'totals' => self::E1_TOTALS,
                'frozen' => true,
                'unitPrices' => [self::VELDSPAR => 19.61, self::BITUMENS => 221.45],
                'pricesReadAt' => self::FROZEN_AT,
                'pricesSyncedAt' => self::JITA_SYNCED_AT,
            ],
            [
                'participants' => $this->participantValuesOf($valuation),
                'totals' => $this->totalsOf($valuation),
                'frozen' => $valuation->frozen,
                'unitPrices' => $unitPrices,
                'pricesReadAt' => $valuation->pricesReadAt->format(\DateTimeInterface::ATOM),
                'pricesSyncedAt' => $valuation->pricesSyncedAt?->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function testFrozenValueOfADeletedAccountKeepsItsShareUnderItsFrozenName(): void
    {
        $operation = $this->frozenE1Operation();
        $this->deleteAccountOf($operation, $this->membershipOf($operation, $this->brann));

        $valuation = $this->valuator->value($operation);

        self::assertSame(
            [
                'shares' => ['Aria Solberg' => 2_429_184, 'Brann Holt' => 2_581_614, 'Cyra Lune' => 158],
                'brannHasNoMember' => true,
                'totals' => self::E1_TOTALS,
            ],
            [
                'shares' => array_map(static fn (array $values): ?int => $values['share'], $this->participantValuesOf($valuation)),
                'brannHasNoMember' => $this->memberlessParticipantNames($valuation) === ['Brann Holt'],
                'totals' => $this->totalsOf($valuation),
            ],
        );
    }

    private function frozenE1Operation(): MiningOperation
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());
        $freezer = new MiningShareFreezer(
            $this->valuator,
            $this->typeNameResolverOfSdeOres(),
            $this->createStub(EntityManagerInterface::class),
            new MockClock(self::FROZEN_AT),
        );
        $freezer->freeze($operation);

        return $operation;
    }

    private function eachForThemselvesE1Operation(): MiningOperation
    {
        $operation = $this->e1Operation(splitMode: MiningSplitMode::EachForThemselves);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        return $operation;
    }

    private function characterValuesOf(MiningOperationValuation $valuation): array
    {
        $values = [];
        foreach ($valuation->participants as $participant) {
            foreach ($participant->characters as $character) {
                $values[$participant->participantName][$character->operationCharacter->getCharacter()->getName()] = $character->minedValue;
            }
            ksort($values[$participant->participantName]);
        }

        return $values;
    }

    private function memberlessParticipantNames(MiningOperationValuation $valuation): array
    {
        return array_values(array_map(
            static fn (MiningParticipantValuation $participant): ?string => $participant->participantName,
            array_filter($valuation->participants, static fn (MiningParticipantValuation $participant): bool => $participant->member === null),
        ));
    }
}
