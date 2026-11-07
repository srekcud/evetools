<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\BlueprintDto;
use App\Entity\Character;

class BlueprintsService
{
    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    /** @return list<BlueprintDto> */
    public function getCharacterBlueprints(Character $character): array
    {
        return [];
    }

    /** @return list<BlueprintDto> */
    public function getCorporationBlueprints(Character $character): array
    {
        return [];
    }
}
