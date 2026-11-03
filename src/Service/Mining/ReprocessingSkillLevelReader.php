<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\Character;
use App\Enum\ReprocessingFailure;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;

class ReprocessingSkillLevelReader
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
    ) {
    }

    public function read(Character $character): ReprocessingSkillLevels|ReprocessingFailure
    {
        return new ReprocessingSkillLevels(-1, -1);
    }
}
