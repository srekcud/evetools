<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;

final readonly class DeliveredSourcingOption
{
    public function __construct(
        public SourcingChoice $kind,
        public Cost $total,
        public Cost $price,
        public Cost $installation,
        public Cost $transport,
        public bool $eligible,
        public ?int $availableQuantity = null,
        public ?string $structureId = null,
    ) {
    }
}
