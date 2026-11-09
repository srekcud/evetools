<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class ProductionOpportunitiesResult
{
    /**
     * @param list<ProductionOpportunity> $rows
     */
    public function __construct(
        public ?int $capitalCap,
        public ?string $capitalStructureName,
        public ?FreeSlots $freeSlots,
        public bool $salesFeesKnown,
        public int $unknownProductCount,
        public array $rows,
    ) {
    }
}
