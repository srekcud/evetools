<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonTaxStatus;

final readonly class MoonTaxSettlementLine
{
    public function __construct(
        public float $paid,
        public ?float $remaining,
        public MoonTaxStatus $status,
    ) {
    }
}
