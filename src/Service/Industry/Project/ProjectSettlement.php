<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectSettlement
{
    /**
     * @param list<SettlementTransfer>|null $transfers
     */
    public function __construct(
        public string $reference,
        public ?array $transfers,
        public ?TransfersUnavailableReason $transfersUnavailableReason,
        public int $roundingGapCents,
        public ClosingChecks $checks,
    ) {
    }
}
