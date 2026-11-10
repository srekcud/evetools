<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Enum\MiningSiteLedgerEtaUnknownReason;

final readonly class LedgerSiteEta
{
    public function __construct(
        public ?\DateTimeImmutable $eta,
        public ?MiningSiteLedgerEtaUnknownReason $unknownReason,
    ) {
    }
}
