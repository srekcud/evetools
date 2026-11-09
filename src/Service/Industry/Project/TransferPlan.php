<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class TransferPlan
{
    /**
     * @param list<PlannedTransfer> $transfers
     */
    public function __construct(
        public array $transfers,
        public int $roundingGapCents,
    ) {
    }
}
