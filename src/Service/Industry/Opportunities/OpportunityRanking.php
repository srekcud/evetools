<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class OpportunityRanking
{
    /**
     * @param list<ProductionOpportunity> $rows
     */
    public function __construct(
        public array $rows,
        public int $unknownProductCount,
    ) {
    }
}
