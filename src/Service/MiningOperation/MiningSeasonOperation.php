<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningSeasonOperation
{
    /**
     * @param list<MiningSeasonParticipant> $participants
     * @param list<MiningSeasonCharacter>   $characters
     */
    public function __construct(
        public int $minutes,
        public ?int $value,
        public array $participants,
        public array $characters = [],
    ) {
    }
}
