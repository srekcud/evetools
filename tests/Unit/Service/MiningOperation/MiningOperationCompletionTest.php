<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Enum\MarketSide;
use App\Enum\MiningShareBlocker;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\MiningOperationValuation;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(MiningShareFreezer::class)]
#[CoversClass(MiningOperationValuator::class)]
#[CoversClass(MiningOperationValuation::class)]
#[Group('issue-265')]
#[AllowMockObjectsWithoutExpectations]
final class MiningOperationCompletionTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string COMPLETED_AT = '2026-11-05T21:58:00+00:00';
    private const string NEXT_JITA_SYNC_AT = '2026-11-05T22:00:00+00:00';

    private EntityManagerInterface&MockObject $entityManager;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = new MockClock(self::COMPLETED_AT);
    }

    public function testCompletingAnEachForThemselvesOperationFreezesItsPricesAndMinedValuesAndArchivesItWithoutPayment(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $this->entityManager->expects($this->once())->method('flush');

        $this->freezerOn($this->jitaSellPrices())->complete($operation, $this->aria);

        self::assertSame(
            [
                'status' => 'completed',
                'archivedAt' => self::COMPLETED_AT,
                'archivedByName' => 'Aria Solberg',
                'sharesFrozenAt' => self::COMPLETED_AT,
                'pricesSyncedAt' => self::JITA_SYNCED_AT,
                'unitPrices' => [self::VELDSPAR => 19.61, self::BITUMENS => 221.45],
                'minedValues' => [[0, 'Aria Solberg', 2_699_093.0], [1, 'Brann Holt', 2_868_460.0], [2, 'Cyra Lune', 176.0]],
                'paymentsCount' => 0,
            ],
            $this->completedStateOf($operation),
        );
    }

    public function testCompletedOperationKeepsItsFrozenPricesAndValuesAfterTheNextJitaSync(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $this->freezerOn($this->jitaSellPrices())->complete($operation, $this->aria);
        $valuatorAfterTheSync = $this->valuatorOn(
            $this->jitaMarketSyncedAt([self::COMPRESSED_VELDSPAR => 25.0] + $this->jitaSellPrices(), [], self::NEXT_JITA_SYNC_AT),
            new MockClock('2026-11-05T22:10:00+00:00'),
        );

        $valuation = $valuatorAfterTheSync->value($operation);

        $unitPrices = $valuation->unitPricesByTypeId;
        ksort($unitPrices);
        self::assertSame(
            [
                'frozen' => true,
                'totalValue' => self::E1_TOTAL_VALUE,
                'unitPrices' => [self::VELDSPAR => 19.61, self::BITUMENS => 221.45],
                'participants' => [
                    'Aria Solberg' => ['state' => 'known', 'minedValue' => self::ARIA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                    'Brann Holt' => ['state' => 'known', 'minedValue' => self::BRANN_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                    'Cyra Lune' => ['state' => 'known', 'minedValue' => self::CYRA_MINED_VALUE, 'share' => null, 'roundingAdjustment' => null],
                ],
                'boosterCut' => null,
                'completionBlockers' => [],
            ],
            [
                'frozen' => $valuation->frozen,
                'totalValue' => $valuation->totalValue,
                'unitPrices' => $unitPrices,
                'participants' => $this->participantValuesOf($valuation),
                'boosterCut' => $valuation->split?->boosterCut,
                'completionBlockers' => $this->completionBlockersOf($valuation),
            ],
        );
    }

    public function testUnknownQuantityOfBrannIsRefusedWithHisNameAndNothingIsFrozen(): void
    {
        $operation = $this->eachForThemselvesE1Operation(unreadStarts: ['Brann II']);
        $this->entityManager->expects($this->never())->method('flush');

        self::assertSame(
            [
                'refusal' => 'Every mined quantity must be known before completing the operation: Brann Holt',
                'state' => [
                    'status' => 'closing',
                    'archivedAt' => null,
                    'archivedByName' => null,
                    'sharesFrozenAt' => null,
                    'pricesSyncedAt' => null,
                    'unitPrices' => [],
                    'minedValues' => [],
                    'paymentsCount' => 0,
                ],
            ],
            ['refusal' => $this->refusalOf($operation), 'state' => $this->completedStateOf($operation)],
        );
    }

    public function testOreWithoutPriceOnTheBasisIsRefusedWithItsName(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::COMPRESSED_BITUMENS]);

        self::assertSame(
            'Some ores have no price on the valuation basis: Bitumens',
            $this->refusalOf($operation, $this->freezerOn($jitaSellPrices)),
        );
    }

    public function testOnlyAClosingOperationCanBeCompleted(): void
    {
        $operation = $this->startedSpodumainOperation();
        $operation->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->entityManager->expects($this->never())->method('flush');

        self::assertSame('Only a closing operation can be completed', $this->refusalOf($operation));
    }

    public function testCompletedOperationCannotBeCompletedAgain(): void
    {
        $operation = $this->eachForThemselvesE1Operation();
        $freezer = $this->freezerOn($this->jitaSellPrices());
        $freezer->complete($operation, $this->aria);

        self::assertSame('Only a closing operation can be completed', $this->refusalOf($operation, $freezer));
    }

    public function testValueProrataOperationIsSettledThroughItsSharesAndPayments(): void
    {
        $operation = $this->withCyraBoostingForTenPercent($this->e1Operation());

        self::assertSame(
            ['refusal' => 'A value prorata operation is settled through its shares and payments', 'status' => 'closing'],
            ['refusal' => $this->refusalOf($operation), 'status' => $operation->getStatus()->value],
        );
    }

    public function testEachForThemselvesOperationWithAnUnknownQuantityIsBlockedFromCompletionButStillHasNoShareToFreeze(): void
    {
        $valuation = $this->valuator()->value($this->eachForThemselvesE1Operation(unreadStarts: ['Brann II']));

        self::assertSame(
            ['completionBlockers' => ['quantities_not_known'], 'freezeBlockers' => ['each_for_themselves']],
            ['completionBlockers' => $this->completionBlockersOf($valuation), 'freezeBlockers' => $this->totalsOf($valuation)['freezeBlockers']],
        );
    }

    public function testEachForThemselvesOperationWithAMissingPriceIsBlockedFromCompletion(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::COMPRESSED_BITUMENS]);
        $valuator = $this->valuatorOn($this->jitaMarketSyncedAt($jitaSellPrices), $this->clock);

        self::assertSame(['prices_missing'], $this->completionBlockersOf($valuator->value($this->eachForThemselvesE1Operation())));
    }

    public function testEachForThemselvesOperationInProgressIsNotClosingAndItsQuantitiesAreStillMeasured(): void
    {
        $operation = $this->startedSpodumainOperation();
        $operation->setSplitMode(MiningSplitMode::EachForThemselves);
        $this->takenStartSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::VELDSPAR, self::S1, 5_000)]);

        self::assertSame(['not_closing', 'quantities_not_known'], $this->completionBlockersOf($this->valuator()->value($operation)));
    }

    public function testClosingEachForThemselvesOperationWithEveryQuantityAndPriceKnownHasNoCompletionBlocker(): void
    {
        self::assertSame([], $this->completionBlockersOf($this->valuator()->value($this->eachForThemselvesE1Operation())));
    }

    public function testValueProrataOperationHasNoCompletionBlockers(): void
    {
        $valuation = $this->valuator()->value($this->withCyraBoostingForTenPercent($this->e1Operation()));

        self::assertNull($valuation->completionBlockers);
    }

    private function eachForThemselvesE1Operation(array $unreadStarts = []): MiningOperation
    {
        $operation = $this->e1Operation(unreadStarts: $unreadStarts, splitMode: MiningSplitMode::EachForThemselves);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        return $operation;
    }

    private function valuator(): MiningOperationValuator
    {
        return $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock);
    }

    private function freezerOn(array $jitaSellPrices): MiningShareFreezer
    {
        return new MiningShareFreezer(
            $this->valuatorOn($this->jitaMarketSyncedAt($jitaSellPrices), $this->clock),
            $this->typeNameResolverOfSdeOres(),
            $this->entityManager,
            $this->clock,
        );
    }

    private function refusalOf(MiningOperation $operation, ?MiningShareFreezer $freezer = null): ?string
    {
        try {
            ($freezer ?? $this->freezerOn($this->jitaSellPrices()))->complete($operation, $this->aria);
        } catch (BadRequestHttpException $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }

    private function completionBlockersOf(MiningOperationValuation $valuation): ?array
    {
        return $valuation->completionBlockers === null
            ? null
            : array_map(static fn (MiningShareBlocker $blocker): string => $blocker->value, $valuation->completionBlockers);
    }

    private function completedStateOf(MiningOperation $operation): array
    {
        $unitPrices = [];
        foreach ($operation->getFrozenUnitPrices() as $frozenUnitPrice) {
            $unitPrices[$frozenUnitPrice->getTypeId()] = $frozenUnitPrice->getUnitPrice();
        }
        ksort($unitPrices);
        $minedValues = array_map(
            static fn (MiningFrozenMinedValue $frozenMinedValue): array => [
                $frozenMinedValue->getPosition(),
                $frozenMinedValue->getParticipantName(),
                $frozenMinedValue->getMinedValue(),
            ],
            array_values($operation->getFrozenMinedValues()->toArray()),
        );
        usort($minedValues, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return [
            'status' => $operation->getStatus()->value,
            'archivedAt' => $operation->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'archivedByName' => $operation->getArchivedByName(),
            'sharesFrozenAt' => $operation->getSharesFrozenAt()?->format(\DateTimeInterface::ATOM),
            'pricesSyncedAt' => $operation->getPricesSyncedAt()?->format(\DateTimeInterface::ATOM),
            'unitPrices' => $unitPrices,
            'minedValues' => $minedValues,
            'paymentsCount' => $operation->getPayments()->count(),
        ];
    }
}
