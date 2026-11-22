<?php

declare(strict_types=1);

namespace App\Service\Industry\Stock;

final readonly class PersonalStock
{
    /**
     * @param array<int, int> $quantitiesByTypeId
     * @param array<int, array{total: int, locations: list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}>}> $locationsByTypeId
     * @param list<PersonalStockContainerReading> $containers
     */
    public function __construct(
        public array $quantitiesByTypeId,
        public array $locationsByTypeId,
        public array $containers,
    ) {
    }

    public function unknownReason(): ?string
    {
        foreach ($this->containers as $container) {
            if (PersonalStockContainerReading::STATUS_UNKNOWN === $container->status) {
                return $container->unknownReason;
            }
        }

        return null;
    }
}
