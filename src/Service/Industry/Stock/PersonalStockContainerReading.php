<?php

declare(strict_types=1);

namespace App\Service\Industry\Stock;

final readonly class PersonalStockContainerReading
{
    public const string STATUS_KNOWN = 'known';
    public const string STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public string $id,
        public int $itemId,
        public string $name,
        public ?string $divisionName,
        public ?int $stationId,
        public ?string $stationName,
        public string $status,
        public ?string $unknownReason,
        public ?int $itemCount,
    ) {
    }
}
