<?php

declare(strict_types=1);

namespace App\Service\ESI;

final readonly class CharacterLocation
{
    public function __construct(
        public int $solarSystemId,
        public ?int $stationId,
        public ?int $structureId,
        public ?bool $online,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }
}
