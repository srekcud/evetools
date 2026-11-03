<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\Character;
use App\Enum\MiningCharacterEligibility;

final readonly class CharacterEligibility
{
    public function __construct(
        public Character $character,
        public MiningCharacterEligibility $eligibility,
        public ?string $busyOperationLabel,
    ) {
    }
}
