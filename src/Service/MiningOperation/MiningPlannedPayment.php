<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningPaymentKind;

final readonly class MiningPlannedPayment
{
    public function __construct(
        public int $position,
        public MiningPaymentKind $kind,
        public ?int $payerPosition,
        public ?int $beneficiaryPosition,
        public int $amount,
    ) {
    }
}
