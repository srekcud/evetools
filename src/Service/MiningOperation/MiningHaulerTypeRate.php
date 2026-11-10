<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningHaulerTypeRate
{
    public function __construct(
        public int $typeId,
        public float $unitsPerMinute,
        public ?float $rawVolumeM3,
        public ?float $transportedVolumeM3,
        public ?bool $compressible,
    ) {
    }
}
