<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\CorporationWalletDonation;
use App\Entity\MoonTaxStatement;
use App\Enum\MoonTaxStatus;

final readonly class MoonTaxSettlement
{
    public const int PAYMENT_GRACE_DAYS = 7;

    private const int MONEY_DECIMALS = 2;

    /**
     * @param list<CorporationWalletDonation> $donations
     * @param list<MoonTaxStatement>          $statements
     *
     * @return array<string, MoonTaxSettlementLine> keyed by statement reference
     */
    public function settle(array $donations, array $statements, \DateTimeImmutable $now): array
    {
        $paidByReference = $this->paidByDonations($donations, $statements);

        usort($statements, static fn (MoonTaxStatement $left, MoonTaxStatement $right): int => $left->getPeriodEnd() <=> $right->getPeriodEnd());

        $lines = [];
        foreach ($statements as $statement) {
            $lines[$statement->getReference()] = $this->lineOf($statement, $paidByReference, $now);
        }

        return $lines;
    }

    /**
     * @param list<CorporationWalletDonation> $donations
     * @param list<MoonTaxStatement>          $statements
     *
     * @return array<string, float>
     */
    private function paidByDonations(array $donations, array $statements): array
    {
        $payable = array_values(array_filter($statements, $this->isPayableByDonations(...)));
        usort($payable, static fn (MoonTaxStatement $left, MoonTaxStatement $right): int => [$left->getPeriodEnd(), $left->getStructureId()] <=> [$right->getPeriodEnd(), $right->getStructureId()]);
        usort($donations, static fn (CorporationWalletDonation $left, CorporationWalletDonation $right): int => $left->getDate() <=> $right->getDate());

        $remainingByReference = [];
        $paidByReference = [];
        foreach ($payable as $statement) {
            $remainingByReference[$statement->getReference()] = (float) $statement->getTax();
            $paidByReference[$statement->getReference()] = 0.0;
        }

        foreach ($donations as $donation) {
            $credit = $donation->getAmount();
            foreach ($payable as $statement) {
                if ($credit <= 0.0) {
                    break;
                }
                if (!$this->donationPays($donation, $statement)) {
                    continue;
                }
                $reference = $statement->getReference();
                $applied = min($credit, $remainingByReference[$reference]);
                $credit = round($credit - $applied, self::MONEY_DECIMALS);
                $remainingByReference[$reference] = round($remainingByReference[$reference] - $applied, self::MONEY_DECIMALS);
                $paidByReference[$reference] = round($paidByReference[$reference] + $applied, self::MONEY_DECIMALS);
            }
        }

        return $paidByReference;
    }

    private function isPayableByDonations(MoonTaxStatement $statement): bool
    {
        return $statement->getCancelledAt() === null && $statement->getMarkedPaidAt() === null && $statement->getTax() !== null;
    }

    private function donationPays(CorporationWalletDonation $donation, MoonTaxStatement $statement): bool
    {
        return $donation->getCorporationId() === $statement->getCorporationId()
            && ($donation->getAttributedCharacterId() ?? $donation->getFirstPartyId()) === $statement->getCharacterId()
            && $donation->getDate() >= $statement->getPeriodStart();
    }

    /**
     * @param array<string, float> $paidByReference
     */
    private function lineOf(MoonTaxStatement $statement, array $paidByReference, \DateTimeImmutable $now): MoonTaxSettlementLine
    {
        $tax = $statement->getTax();

        if ($statement->getCancelledAt() !== null) {
            return new MoonTaxSettlementLine(0.0, 0.0, MoonTaxStatus::Cancelled);
        }
        if ($statement->getMarkedPaidAt() !== null) {
            return new MoonTaxSettlementLine($tax === null ? 0.0 : $tax, 0.0, MoonTaxStatus::Paid);
        }
        if ($tax === null) {
            return new MoonTaxSettlementLine(0.0, null, MoonTaxStatus::Unknown);
        }

        $paidByDonations = $paidByReference[$statement->getReference()];
        $remaining = round($tax - $paidByDonations, self::MONEY_DECIMALS);
        if ($remaining <= 0.0) {
            return new MoonTaxSettlementLine($paidByDonations, 0.0, MoonTaxStatus::Paid);
        }

        return new MoonTaxSettlementLine($paidByDonations, $remaining, $this->unsettledStatus($statement, $paidByDonations, $now));
    }

    private function unsettledStatus(MoonTaxStatement $statement, float $paid, \DateTimeImmutable $now): MoonTaxStatus
    {
        $lateAfter = $statement->getPeriodEnd()->modify(sprintf('+%d days', self::PAYMENT_GRACE_DAYS));
        if ($now > $lateAfter) {
            return MoonTaxStatus::Late;
        }

        return $paid > 0.0 ? MoonTaxStatus::Partial : MoonTaxStatus::Due;
    }
}
