<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningPresenceState;

final readonly class MiningPresence
{
    public function __construct(
        public MiningPresenceState $state,
        public ?int $idleMinutes,
    ) {
    }
}
