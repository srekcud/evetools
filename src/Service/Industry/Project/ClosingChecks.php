<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ClosingChecks
{
    /**
     * @param list<UnsoldProduct> $unsoldProducts
     */
    public function __construct(
        public int $pendingContributionCount,
        public array $unsoldProducts,
        public ?float $marginPercent,
        public ?int $transfersTotal,
        public ?int $transfersReceived,
    ) {
    }
}
