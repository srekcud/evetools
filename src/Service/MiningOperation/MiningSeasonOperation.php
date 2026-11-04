<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonOperation
{
    /**
     * @param list<MiningSeasonParticipant> $participants
     */
    public function __construct(
        public int $minutes,
        public ?int $value,
        public array $participants,
    ) {
    }
}
