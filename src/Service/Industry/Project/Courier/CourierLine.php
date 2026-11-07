<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierLine
{
    public function __construct(
        public string $bomLineId,
        public int $typeId,
        public int $purchasedQuantity,
        public CourierContractState $transportState,
    ) {
    }
}
