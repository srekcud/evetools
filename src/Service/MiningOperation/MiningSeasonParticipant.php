<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonParticipant
{
    public function __construct(
        public string $userKey,
        public string $name,
        public int $minutes,
        public ?int $minedValue,
    ) {
    }
}
