<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class TypeStationQuantities
{
    /**
     * @param list<string> $lineIds
     * @param list<StationQuantity>|null $stations null when the plan or the consumption of the started jobs cannot be read
     */
    public function __construct(
        public int $typeId,
        public string $typeName,
        public array $lineIds,
        public ?array $stations,
        public ?string $unknownReason,
    ) {
    }
}
