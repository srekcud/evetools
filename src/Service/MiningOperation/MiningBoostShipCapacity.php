<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningBoostShipCapacity
{
    public function __construct(
        public float $hangarM3,
        public float $holdM3,
    ) {
    }
}
