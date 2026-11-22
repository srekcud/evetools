<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class DeliveredSourcing
{
    /**
     * @param list<DeliveredSourcingLine> $lines
     * @param list<int>                   $boughtTypeIds
     */
    public function __construct(
        public array $lines,
        public DeliveredSourcingTotals $totals,
        public array $boughtTypeIds,
    ) {
    }
}
