<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class StationQuantity
{
    public function __construct(
        public ?int $locationId,
        public ?string $stationName,
        public int $needed,
        public int $consumed,
        public int $inProduction,
        public ?int $onSite,
        public ?int $out,
        public ?int $toBring,
        public ?int $inTransit,
        public ?string $unknownReason,
    ) {
    }
}
