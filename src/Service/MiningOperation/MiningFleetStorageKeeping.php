<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningFleetStorageDelivery;

final readonly class MiningFleetStorageKeeping
{
    public function __construct(
        public ?int $keptValue,
        public ?int $deliveredValue,
    ) {
    }

    /**
     * @param array<int, int>|null $deliveredQuantitiesByTypeId
     * @param array<int, ?float>   $unitPricesByTypeId
     */
    public static function of(MinedQuantity $quantity, ?int $minedValue, MiningFleetStorageDelivery $delivery, ?array $deliveredQuantitiesByTypeId, array $unitPricesByTypeId): self
    {
        return new self(0, 0);
    }
}
