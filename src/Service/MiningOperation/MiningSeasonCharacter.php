<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonCharacter
{
    public function __construct(
        public string $characterKey,
        public ?int $shipTypeId,
        public int $minutes,
        public ?int $minedValue,
        public bool $isBooster,
    ) {
    }
}
