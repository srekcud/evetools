<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class WorkTransportLeg
{
    public function __construct(
        public WorkTransportKind $kind,
        public ?string $fromStructureId,
        public ?string $toStructureId,
        public float $cubicMeters,
        public ?float $cost,
    ) {
    }
}
