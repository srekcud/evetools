<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class IntermediateStockStationQuantity
{
    public function __construct(
        public int $locationId,
        public string $locationName,
        public ?string $systemName,
        public int $quantity,
        public ?bool $onSite,
    ) {
    }
}
