<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\CorporationWalletDonation;
use App\Entity\MoonTaxStatement;

final readonly class MoonTaxSettlement
{
    public const int PAYMENT_GRACE_DAYS = 7;

    /**
     * @param list<CorporationWalletDonation> $donations
     * @param list<MoonTaxStatement>          $statements
     *
     * @return array<string, MoonTaxSettlementLine> keyed by statement reference
     */
    public function settle(array $donations, array $statements, \DateTimeImmutable $now): array
    {
        return [];
    }
}
