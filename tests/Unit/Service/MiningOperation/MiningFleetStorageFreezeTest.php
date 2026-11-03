<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningSplitMode;
use App\Service\MiningOperation\MiningFleetStorageCalculator;
use App\Service\MiningOperation\MiningFleetStorageKeeping;
use App\Service\MiningOperation\MiningOperationValuation;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(MiningShareFreezer::class)]
#[CoversClass(MiningOperationValuator::class)]
#[CoversClass(MiningFleetStorageCalculator::class)]
#[CoversClass(MiningFleetStorageKeeping::class)]
#[CoversClass(MiningFrozenMinedValue::class)]
#[Group('issue-266')]
final class MiningFleetStorageFreezeTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string CORPORATION_NAME = 'Corpo Exemple';
    private const string CONTAINER_NAME = 'OPS-HAUL';
    private const string READ_AFTER_PRICE_CHANGE_AT = '2026-11-05T23:10:00+00:00';
    private const float COMPRESSED_VELDSPAR_AFTER_SYNC = 25.0;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->character('Aria Solberg')->setCorporationName(self::CORPORATION_NAME);
        $this->clock = new MockClock(self::FROZEN_AT);
    }

    public function testWithoutFleetStorageTheBoosterCutPaymentsAreThoseOfTheTenPercentSplitAndNothingIsDelivered(): void
    {
        $operation = $this->e1OperationSplitWith(10, 0);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                'payments' => [
                    [0, 'booster_cut', 'Aria Solberg', 'Cyra Lune', 269_909.0],
                    [1, 'booster_cut', 'Brann Holt', 'Cyra Lune', 286_846.0],
                ],
                'deliveredValues' => [0.0, 0.0, 0.0],
            ],
            ['payments' => $this->paymentsOf($operation), 'deliveredValues' => $this->frozenDeliveredValuesOf($operation)],
        );
    }

    public function testWithoutFleetStorageTheBoosterAndCorporationCutPaymentsAreUnchanged(): void
    {
        $operation = $this->e1OperationSplitWith(10, 5);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'booster_cut', 'Aria Solberg', 'Cyra Lune', 404_864.0],
                [1, 'booster_cut', 'Brann Holt', 'Cyra Lune', 430_269.0],
                [2, 'collected_corporation_cut', 'Cyra Lune', self::CORPORATION_NAME, 278_386.0],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testBoosterRecipientOfEverythingOwesEachDepositorHisShareAndCollectsNoBoosterCutLine(): void
    {
        $operation = $this->e1OperationSplitWith(10, 0);
        $this->assignRecipient($operation, $this->cyra);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);
        $this->declare($operation, $this->brann, MiningFleetStorageDelivery::All);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'fleet_storage_share', 'Cyra Lune', 'Aria Solberg', 2_429_184.0],
                [1, 'fleet_storage_share', 'Cyra Lune', 'Brann Holt', 2_581_614.0],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testRecipientPaysTheBoosterCutOfTheOreHeHoldsThenOwesThePartialDepositorHisShareMinusWhatHeKept(): void
    {
        $operation = $this->fs2Operation();

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'booster_cut', 'Brann Holt', 'Cyra Lune', 556_755.0],
                [1, 'fleet_storage_share', 'Brann Holt', 'Aria Solberg', 1_691_091.0],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testFleetStorageLineCopiesTheMembersOfTheRecipientAndOfTheDepositor(): void
    {
        $operation = $this->fs2Operation();

        $this->freezer()->freeze($operation);

        $fleetStorageLines = array_values(array_filter(
            $operation->getPayments()->toArray(),
            static fn (MiningPayment $payment): bool => $payment->getKind()->value === 'fleet_storage_share',
        ));
        self::assertSame(
            [[true, true, false]],
            array_map(fn (MiningPayment $payment): array => [
                $payment->getPayerMember() === $this->membershipOf($operation, $this->brann),
                $payment->getBeneficiaryMember() === $this->membershipOf($operation, $this->aria),
                $payment->isPaid(),
            ], $fleetStorageLines),
        );
    }

    public function testDepositorWhoKeptMoreThanHisShareIsOwedNothingAndPaysTheBoosterTheDifference(): void
    {
        $operation = $this->fs2Operation([self::VELDSPAR => 10_000]);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'booster_cut', 'Aria Solberg', 'Cyra Lune', 73_809.0],
                [1, 'booster_cut', 'Brann Holt', 'Cyra Lune', 482_946.0],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testBoosterRecipientWithACorporationCutCollectsItThenOwesTheDepositorHisShare(): void
    {
        $operation = $this->e1OperationSplitWith(10, 5);
        $this->assignRecipient($operation, $this->cyra);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'booster_cut', 'Brann Holt', 'Cyra Lune', 430_269.0],
                [1, 'collected_corporation_cut', 'Cyra Lune', self::CORPORATION_NAME, 278_386.0],
                [2, 'fleet_storage_share', 'Cyra Lune', 'Aria Solberg', 2_294_229.0],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testRecipientOfACorporationOnlyOperationPaysTheCorporationCutOfTheOreHeHolds(): void
    {
        $operation = $this->e1OperationSplitWith(0, 5);
        $this->assignRecipient($operation, $this->brann);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'corporation_cut', 'Brann Holt', self::CORPORATION_NAME, 278_378.0],
                [1, 'corporation_cut', 'Cyra Lune', self::CORPORATION_NAME, 9.0],
                [2, 'fleet_storage_share', 'Brann Holt', 'Aria Solberg', 2_564_138.0],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testFreezingFixesTheDeliveredValuesAndTheDebtNoLongerFollowsTheMarket(): void
    {
        $operation = $this->fs2Operation();
        $this->freezer()->freeze($operation);
        $deliveredValues = $this->frozenDeliveredValuesOf($operation);
        $jitaAfterSync = [self::COMPRESSED_VELDSPAR => self::COMPRESSED_VELDSPAR_AFTER_SYNC] + $this->jitaSellPrices();

        $valuation = $this->valuatorOn($this->jitaMarketSyncedAt($jitaAfterSync, [], '2026-11-05T23:00:00+00:00'), new MockClock(self::READ_AFTER_PRICE_CHANGE_AT))->value($operation);

        self::assertSame(
            [
                'deliveredValues' => [1_961_000.0, 0.0, 0.0],
                'aria' => ['partial', false, 1_961_000, 738_093, 1_691_091],
            ],
            ['deliveredValues' => $deliveredValues, 'aria' => $this->fleetStorageOf($valuation)['Aria Solberg']],
        );
    }

    public function testDeliveredQuantityAboveWhatWasMinedRefusesTheFreezeWithTheParticipantNamesAndFreezesNothing(): void
    {
        $operation = $this->fs2Operation([self::VELDSPAR => 100_001]);

        $refusal = $this->refusalOfFreezing($operation);

        self::assertSame(
            [
                'refusal' => 'Some delivered quantities exceed what was mined: Aria Solberg',
                'freezeBlockers' => ['fleet_storage_exceeds_mined'],
                'status' => 'closing',
                'frozenMinedValues' => 0,
                'payments' => [],
            ],
            [
                'refusal' => $refusal,
                'freezeBlockers' => $this->totalsOf($this->valuation($operation))['freezeBlockers'],
                'status' => $operation->getStatus()->value,
                'frozenMinedValues' => $operation->getFrozenMinedValues()->count(),
                'payments' => $this->paymentsOf($operation),
            ],
        );
    }

    public function testDeliveryTowardsARecipientRemovedFromTheOperationRefusesTheFreeze(): void
    {
        $operation = $this->e1OperationSplitWith(10, 0);
        $this->assignRecipient($operation, $this->brann);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);
        $this->membershipOf($operation, $this->brann)->remove();

        self::assertSame(
            ['refusal' => 'Choose the fleet storage recipient', 'freezeBlockers' => ['fleet_storage_recipient_missing']],
            ['refusal' => $this->refusalOfFreezing($operation), 'freezeBlockers' => $this->totalsOf($this->valuation($operation))['freezeBlockers']],
        );
    }

    public function testDeliveryTowardsARecipientWhoseAccountWasDeletedRefusesTheFreeze(): void
    {
        $operation = $this->e1OperationSplitWith(10, 0);
        $this->assignRecipient($operation, $this->brann);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::All);
        $this->deleteRecipientAccount($operation, $this->brann);

        self::assertSame(
            ['refusal' => 'Choose the fleet storage recipient', 'freezeBlockers' => ['fleet_storage_recipient_missing']],
            ['refusal' => $this->refusalOfFreezing($operation), 'freezeBlockers' => $this->totalsOf($this->valuation($operation))['freezeBlockers']],
        );
    }

    public function testRecipientWhoseAccountWasDeletedDoesNotBlockTheFreezeWhenNobodyDelivers(): void
    {
        $operation = $this->e1OperationSplitWith(10, 0);
        $this->assignRecipient($operation, $this->brann);
        $this->deleteRecipientAccount($operation, $this->brann);

        $this->freezer()->freeze($operation);

        self::assertSame(
            ['status' => 'shares', 'payments' => [[0, 'booster_cut', 'Aria Solberg', 'Cyra Lune', 269_909.0]]],
            ['status' => $operation->getStatus()->value, 'payments' => $this->paymentsOf($operation)],
        );
    }

    public function testUnknownQuantityComesFirstAmongTheBlockersAndGivesTheRefusalMessage(): void
    {
        $operation = $this->fs2Operation([self::VELDSPAR => 100_001], unreadStarts: ['Brann II']);

        self::assertSame(
            [
                'refusal' => 'Every mined quantity must be known before freezing the shares: Brann Holt',
                'freezeBlockers' => ['quantities_not_known', 'fleet_storage_exceeds_mined'],
            ],
            ['refusal' => $this->refusalOfFreezing($operation), 'freezeBlockers' => $this->totalsOf($this->valuation($operation))['freezeBlockers']],
        );
    }

    public function testClosingPreviewShowsTheDeclarationTheKeptAndDeliveredValuesAndTheDebtOfEachParticipant(): void
    {
        $valuation = $this->valuation($this->fs2Operation());

        self::assertSame(
            [
                'Aria Solberg' => ['partial', false, 1_961_000, 738_093, 1_691_091],
                'Brann Holt' => ['none', true, 0, self::BRANN_MINED_VALUE, 0],
                'Cyra Lune' => ['none', false, 0, self::CYRA_MINED_VALUE, 0],
            ],
            $this->fleetStorageOf($valuation),
        );
    }

    public function testUnknownSharesLeaveEveryDebtUnknownButKeepTheKnownKeptValue(): void
    {
        $valuation = $this->valuation($this->fs2Operation(unreadStarts: ['Brann II']));

        self::assertSame(
            [
                'Aria Solberg' => ['partial', false, 1_961_000, 738_093, null],
                'Brann Holt' => ['none', true, null, null, null],
                'Cyra Lune' => ['none', false, 0, self::CYRA_MINED_VALUE, null],
            ],
            $this->fleetStorageOf($valuation),
        );
    }

    public function testDeliveredQuantityAboveWhatWasMinedLeavesTheKeptValueTheDeliveredValueAndTheDebtUnknown(): void
    {
        $valuation = $this->valuation($this->fs2Operation([self::VELDSPAR => 100_001]));

        self::assertSame(['partial', false, null, null, null], $this->fleetStorageOf($valuation)['Aria Solberg']);
    }

    public function testEachForThemselvesParticipantsKeepTheirMinedValueAndHaveNoDebt(): void
    {
        $valuation = $this->valuation($this->e1Operation(splitMode: MiningSplitMode::EachForThemselves));

        self::assertSame(
            [
                'Aria Solberg' => ['none', false, 0, self::ARIA_MINED_VALUE, null],
                'Brann Holt' => ['none', false, 0, self::BRANN_MINED_VALUE, null],
                'Cyra Lune' => ['none', false, 0, self::CYRA_MINED_VALUE, null],
            ],
            $this->fleetStorageOf($valuation),
        );
    }

    private function fs2Operation(array $ariaDeliveredQuantities = [self::VELDSPAR => 100_000], array $unreadStarts = []): MiningOperation
    {
        $operation = $this->e1OperationSplitWith(10, 0, $unreadStarts);
        $this->assignRecipient($operation, $this->brann);
        $this->declare($operation, $this->aria, MiningFleetStorageDelivery::Partial, $ariaDeliveredQuantities);

        return $operation;
    }

    private function e1OperationSplitWith(int $boosterCutPercent, int $corporationCutPercent, array $unreadStarts = []): MiningOperation
    {
        $operation = $this->e1Operation(unreadStarts: $unreadStarts);
        $operation->updateValuationSettings(
            valuationSide: MarketSide::Sell,
            boosterCutPercent: $boosterCutPercent,
            corporationCutPercent: $corporationCutPercent,
            boosterMember: $boosterCutPercent > 0 ? $this->membershipOf($operation, $this->cyra) : null,
        );

        return $operation;
    }

    private function assignRecipient(MiningOperation $operation, User $recipient): void
    {
        $operation->assignFleetStorage($this->membershipOf($operation, $recipient), self::CONTAINER_NAME);
    }

    private function declare(MiningOperation $operation, User $participant, MiningFleetStorageDelivery $delivery, ?array $quantities = null): void
    {
        $this->membershipOf($operation, $participant)->declareFleetStorage($delivery, $quantities);
    }

    private function deleteRecipientAccount(MiningOperation $operation, User $recipient): void
    {
        $this->deleteAccountOf($operation, $this->membershipOf($operation, $recipient));
        (new \ReflectionProperty(MiningOperation::class, 'fleetStorageRecipient'))->setValue($operation, null);
    }

    private function freezer(): MiningShareFreezer
    {
        return new MiningShareFreezer(
            $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock),
            $this->typeNameResolverOfSdeOres(),
            $this->createStub(EntityManagerInterface::class),
            $this->clock,
        );
    }

    private function valuation(MiningOperation $operation): MiningOperationValuation
    {
        return $this->valuatorOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $this->clock)->value($operation);
    }

    private function refusalOfFreezing(MiningOperation $operation): ?string
    {
        try {
            $this->freezer()->freeze($operation);
        } catch (BadRequestHttpException $refusal) {
            return $refusal->getMessage();
        }

        return null;
    }

    private function paymentsOf(MiningOperation $operation): array
    {
        $payments = array_map(
            static fn (MiningPayment $payment): array => [
                $payment->getPosition(),
                $payment->getKind()->value,
                $payment->getPayerName(),
                $payment->getBeneficiaryName(),
                $payment->getAmount(),
            ],
            array_values($operation->getPayments()->toArray()),
        );
        usort($payments, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $payments;
    }

    private function frozenDeliveredValuesOf(MiningOperation $operation): array
    {
        $frozenMinedValues = array_values($operation->getFrozenMinedValues()->toArray());
        usort($frozenMinedValues, static fn (MiningFrozenMinedValue $left, MiningFrozenMinedValue $right): int => $left->getPosition() <=> $right->getPosition());

        return array_map(static fn (MiningFrozenMinedValue $frozenMinedValue): float => $frozenMinedValue->getDeliveredValue(), $frozenMinedValues);
    }

    private function fleetStorageOf(MiningOperationValuation $valuation): array
    {
        $fleetStorage = [];
        foreach ($valuation->participants as $participant) {
            $fleetStorage[$participant->participantName] = [
                $participant->fleetStorageDelivery?->value,
                $participant->isFleetStorageRecipient,
                $participant->deliveredValue,
                $participant->keptValue,
                $participant->fleetStorageDebt,
            ];
        }

        return $fleetStorage;
    }
}
