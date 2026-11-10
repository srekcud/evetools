<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonSite
{
    public function __construct(
        public ?int $solarSystemId,
        public string $label,
        public int $scansCount,
        public int $observedSeconds,
        public ?float $extractedValue,
    ) {
    }
}
