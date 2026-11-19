<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PurchaseToDeliver
{
    public function __construct(
        public string $bomLineId,
        public int $typeId,
        public PurchaseOriginKind $originKind,
        public ?int $solarSystemId,
        public ?string $solarSystemName,
        public int $purchasedQuantity,
        public ?int $missingQuantity,
        public PurchaseDeliveryState $deliveryState,
    ) {
    }
}
