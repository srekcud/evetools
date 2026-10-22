<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;

class CorporationService
{
    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    /**
     * @return array<int, string>
     *
     * @throws EveAuthRequiredException when the character has no ESI token
     * @throws EsiApiException
     */
    public function getDivisions(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            throw new EveAuthRequiredException((string) $character->getEveCharacterId());
        }

        $corporationId = $character->getCorporationId();
        $data = $this->esiClient->get("/corporations/{$corporationId}/divisions/", $token);

        $divisions = [];

        // Hangar divisions
        foreach ($data['hangar'] ?? [] as $division) {
            $divisionNumber = $division['division'];
            $name = $division['name'] ?? "Division {$divisionNumber}";
            $divisions[$divisionNumber] = $name;
        }

        return $divisions;
    }
}
