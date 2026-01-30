<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\CharacterInfoDto;
use App\Entity\Character;

class CharacterService
{
    public function __construct(
        private readonly EsiClient $esiClient,
    ) {
    }

    public function getCharacterInfo(int $characterId): CharacterInfoDto
    {
        // Get character public info
        $characterData = $this->esiClient->getWithCache("/characters/{$characterId}/");

        // Get corporation info
        $corporationId = $characterData['corporation_id'];
        $corporationData = $this->esiClient->getWithCache("/corporations/{$corporationId}/");

        // Get alliance info if exists
        $allianceId = $characterData['alliance_id'] ?? null;
        $allianceName = null;

        if ($allianceId !== null) {
            $allianceData = $this->esiClient->getWithCache("/alliances/{$allianceId}/");
            $allianceName = $allianceData['name'];
        }

        return new CharacterInfoDto(
            characterId: $characterId,
            characterName: $characterData['name'],
            corporationId: $corporationId,
            corporationName: $corporationData['name'],
            allianceId: $allianceId,
            allianceName: $allianceName,
        );
    }

    public function refreshCharacterInfo(Character $character): CharacterInfoDto
    {
        $info = $this->getCharacterInfo($character->getEveCharacterId());

        $character->setName($info->characterName);
        $character->setCorporationId($info->corporationId);
        $character->setCorporationName($info->corporationName);
        $character->setAllianceId($info->allianceId);
        $character->setAllianceName($info->allianceName);

        return $info;
    }

    /**
     * @return array<string>
     */
    public function getCharacterRoles(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $characterId = $character->getEveCharacterId();
        $data = $this->esiClient->get("/characters/{$characterId}/roles/", $token);

        return $data['roles'] ?? [];
    }

    public function hasRole(Character $character, string $role): bool
    {
        $roles = $this->getCharacterRoles($character);

        return in_array($role, $roles, true);
    }

    public function canReadCorporationAssets(Character $character): bool
    {
        return $this->hasRole($character, 'Director') || $this->hasRole($character, 'Hangar_Take_1');
    }
}
