<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final readonly class HaulUnreadable
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public ?int $locationId,
        public string $reason,
    ) {
    }
}
