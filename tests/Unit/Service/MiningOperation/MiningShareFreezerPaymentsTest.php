<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Enum\MarketSide;
use App\Service\MiningOperation\MiningPaymentPlanner;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[CoversClass(MiningShareFreezer::class)]
#[CoversClass(MiningPayment::class)]
#[CoversClass(MiningPaymentPlanner::class)]
#[Group('issue-265')]
final class MiningShareFreezerPaymentsTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const string CORPORATION_NAME = 'Corpo Exemple';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->character('Aria Solberg')->setCorporationName(self::CORPORATION_NAME);
        $this->clock = new MockClock(self::FROZEN_AT);
    }

    public function testFreezingWithBoosterAndCorporationCutsCreatesTheUnpaidPaymentsTowardsTheBoosterThenTheCorporation(): void
    {
        $operation = $this->e1OperationSplitWith(10, 5);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                'status' => 'shares',
                'payments' => [
                    [0, 'booster_cut', 'Aria Solberg', 'aria', 'Cyra Lune', 'cyra', 404_864.0, null, null, null],
                    [1, 'booster_cut', 'Brann Holt', 'brann', 'Cyra Lune', 'cyra', 430_269.0, null, null, null],
                    [2, 'collected_corporation_cut', 'Cyra Lune', 'cyra', self::CORPORATION_NAME, null, 278_386.0, null, null, null],
                ],
            ],
            ['status' => $operation->getStatus()->value, 'payments' => $this->paymentsOf($operation)],
        );
    }

    public function testFreezingWithATenPercentBoosterCutCreatesOneBoosterCutPaymentPerOtherParticipant(): void
    {
        $operation = $this->e1OperationSplitWith(10, 0);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'booster_cut', 'Aria Solberg', 'aria', 'Cyra Lune', 'cyra', 269_909.0, null, null, null],
                [1, 'booster_cut', 'Brann Holt', 'brann', 'Cyra Lune', 'cyra', 286_846.0, null, null, null],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testFreezingWithoutBoosterNorCorporationCutCreatesNoPayment(): void
    {
        $operation = $this->e1Operation();
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        $this->freezer()->freeze($operation);

        self::assertSame(['status' => 'shares', 'payments' => []], ['status' => $operation->getStatus()->value, 'payments' => $this->paymentsOf($operation)]);
    }

    public function testCorporationOnlyPaymentsAreNamedAfterTheCorporationOfTheOwnerMainCharacter(): void
    {
        $operation = $this->e1OperationSplitWith(0, 5);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [
                [0, 'corporation_cut', 'Aria Solberg', 'aria', self::CORPORATION_NAME, null, 134_955.0, null, null, null],
                [1, 'corporation_cut', 'Brann Holt', 'brann', self::CORPORATION_NAME, null, 143_423.0, null, null, null],
                [2, 'corporation_cut', 'Cyra Lune', 'cyra', self::CORPORATION_NAME, null, 9.0, null, null, null],
            ],
            $this->paymentsOf($operation),
        );
    }

    public function testCorporationIsUnnamedWhenTheOwnerMainCharacterBelongsToAnotherCorporationThanTheOperation(): void
    {
        $this->character('Aria Solberg')->setCorporationId(self::C2);
        $operation = $this->e1OperationSplitWith(0, 5);

        $this->freezer()->freeze($operation);

        self::assertSame(
            [null, null, null],
            array_map(static fn (array $payment): ?string => $payment[4], $this->paymentsOf($operation)),
        );
    }

    public function testRefusedFreezeCreatesNoPayment(): void
    {
        $operation = $this->e1Operation(unreadStarts: ['Brann II']);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10, corporationCutPercent: 5, boosterMember: $this->membershipOf($operation, $this->cyra));

        try {
            $this->freezer()->freeze($operation);
        } catch (BadRequestHttpException) {
        }

        self::assertSame(['status' => 'closing', 'payments' => []], ['status' => $operation->getStatus()->value, 'payments' => $this->paymentsOf($operation)]);
    }

    private function e1OperationSplitWith(int $boosterCutPercent, int $corporationCutPercent): MiningOperation
    {
        $operation = $this->e1Operation();
        $operation->updateValuationSettings(
            valuationSide: MarketSide::Sell,
            boosterCutPercent: $boosterCutPercent,
            corporationCutPercent: $corporationCutPercent,
            boosterMember: $boosterCutPercent > 0 ? $this->membershipOf($operation, $this->cyra) : null,
        );

        return $operation;
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

    private function paymentsOf(MiningOperation $operation): array
    {
        $payments = array_map(
            fn (MiningPayment $payment): array => [
                $payment->getPosition(),
                $payment->getKind()->value,
                $payment->getPayerName(),
                $this->pilotNameOf($operation, $payment->getPayerMember()),
                $payment->getBeneficiaryName(),
                $this->pilotNameOf($operation, $payment->getBeneficiaryMember()),
                $payment->getAmount(),
                $payment->getPaidAt()?->format(\DateTimeInterface::ATOM),
                $payment->getPaidBy(),
                $payment->getPaidByName(),
            ],
            array_values($operation->getPayments()->toArray()),
        );
        usort($payments, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $payments;
    }

    private function pilotNameOf(MiningOperation $operation, mixed $member): ?string
    {
        foreach (['aria' => $this->aria, 'brann' => $this->brann, 'cyra' => $this->cyra] as $pilotName => $pilot) {
            if ($member !== null && $member === $this->membershipOf($operation, $pilot)) {
                return $pilotName;
            }
        }

        return null;
    }
}
