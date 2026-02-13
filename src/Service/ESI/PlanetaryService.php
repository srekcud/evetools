<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;

class PlanetaryService
{
    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    /**
     * Fetch the list of planetary colonies for a character.
     *
     * @return array<array<string, mixed>>
     */
    public function fetchColonies(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $characterId = $character->getEveCharacterId();

        return $this->esiClient->get("/characters/{$characterId}/planets/", $token);
    }

    /**
     * Fetch detailed info for a specific colony (pins, routes, links).
     *
     * @return array<string, mixed>
     */
    public function fetchColonyDetail(Character $character, int $planetId): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $characterId = $character->getEveCharacterId();

        return $this->esiClient->get("/characters/{$characterId}/planets/{$planetId}/", $token);
    }

    /**
     * Fetch planet info from ESI (public endpoint, no auth needed).
     *
     * @return array{name: string, planet_id: int, system_id: int, type_id: int}
     */
    public function fetchPlanetInfo(int $planetId): array
    {
        return $this->esiClient->get("/universe/planets/{$planetId}/");
    }
}
