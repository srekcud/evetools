<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final readonly class HaulLine
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public ?int $quantity,
        public HaulLineState $state,
        public HaulLineSource $source,
        public ?\DateTimeImmutable $readyAt,
        public ?float $unitVolume,
        public ?float $volume,
        public ?string $unknownReason,
    ) {
    }
}
