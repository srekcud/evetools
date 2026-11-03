<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningFrozenMinedValue;
use App\Entity\MiningFrozenUnitPrice;
use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Enum\MarketSide;
use App\Enum\MiningPaymentKind;
use Symfony\Component\Uid\Uuid;

trait BuildsMiningPayments
{
    private const string CORPORATION_NAME = 'Corpo Exemple';
    private const string PAID_AT = '2026-11-05T22:24:00+00:00';
    private const string SETTLED_AT = '2026-11-05T23:35:00+00:00';
    private const int G1_ARIA_PAYMENT = 404_864;
    private const int G1_BRANN_PAYMENT = 430_269;
    private const int G1_COLLECTED_CORPORATION_CUT = 278_386;

    private function g1Operation(): MiningOperation
    {
        $this->character('Aria Solberg')->setCorporationName(self::CORPORATION_NAME);
        $operation = $this->e1Operation();
        $aria = $this->membershipOf($operation, $this->aria);
        $brann = $this->membershipOf($operation, $this->brann);
        $cyra = $this->membershipOf($operation, $this->cyra);
        $operation->updateValuationSettings(valuationSide: MarketSide::Sell, boosterCutPercent: 10, corporationCutPercent: 5, boosterMember: $cyra);
        $operation->freezeShares(
            new \DateTimeImmutable(self::FROZEN_AT),
            new \DateTimeImmutable(self::JITA_SYNCED_AT),
            [
                new MiningFrozenUnitPrice($operation, self::VELDSPAR, 19.61),
                new MiningFrozenUnitPrice($operation, self::BITUMENS, 221.45),
            ],
            [
                new MiningFrozenMinedValue($operation, $aria, 'Aria Solberg', 0, (float) self::ARIA_MINED_VALUE),
                new MiningFrozenMinedValue($operation, $brann, 'Brann Holt', 1, (float) self::BRANN_MINED_VALUE),
                new MiningFrozenMinedValue($operation, $cyra, 'Cyra Lune', 2, (float) self::CYRA_MINED_VALUE),
            ],
        );
        foreach ([
            new MiningPayment($operation, 0, MiningPaymentKind::BoosterCut, $aria, 'Aria Solberg', $cyra, 'Cyra Lune', (float) self::G1_ARIA_PAYMENT),
            new MiningPayment($operation, 1, MiningPaymentKind::BoosterCut, $brann, 'Brann Holt', $cyra, 'Cyra Lune', (float) self::G1_BRANN_PAYMENT),
            new MiningPayment($operation, 2, MiningPaymentKind::CollectedCorporationCut, $cyra, 'Cyra Lune', null, self::CORPORATION_NAME, (float) self::G1_COLLECTED_CORPORATION_CUT),
        ] as $payment) {
            (new \ReflectionProperty(MiningPayment::class, 'id'))->setValue($payment, Uuid::v4());
            $operation->getPayments()->add($payment);
        }

        return $operation;
    }

    private function g1OperationWithEveryPaymentPaid(): MiningOperation
    {
        $operation = $this->g1Operation();
        foreach ($operation->getPayments() as $payment) {
            $payment->markPaid($this->aria, 'Aria Solberg', new \DateTimeImmutable(self::PAID_AT));
        }

        return $operation;
    }

    private function paymentAt(MiningOperation $operation, int $position): MiningPayment
    {
        foreach ($operation->getPayments() as $payment) {
            if ($payment->getPosition() === $position) {
                return $payment;
            }
        }

        throw new \LogicException(sprintf('No payment at position %d', $position));
    }

    private function paymentStateOf(MiningPayment $payment): array
    {
        return [
            'paid' => $payment->isPaid(),
            'paidAt' => $payment->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'paidBy' => $payment->getPaidBy()?->getMainCharacter()?->getName(),
            'paidByName' => $payment->getPaidByName(),
        ];
    }
}
