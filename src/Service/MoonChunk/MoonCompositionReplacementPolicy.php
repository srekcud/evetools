<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

use App\Entity\User;
use App\Repository\MoonCompositionRepository;
use App\Service\ESI\CharacterService;

final readonly class MoonCompositionReplacementPolicy
{
    public function __construct(
        private MoonCompositionRepository $compositionRepository,
        private CharacterService $characterService,
    ) {
    }

    public function assertMayRecord(User $user, int $corporationId, int $moonId): void
    {
    }
}
