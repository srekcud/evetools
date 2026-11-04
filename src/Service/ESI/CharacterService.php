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
        $corporationId = $characterData['corporation_id'] ?? null;
        if (!is_int($corporationId)) {
            throw new \UnexpectedValueException("ESI character {$characterId} response from /characters/{$characterId}/ has no integer 'corporation_id'");
        }
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

    /**
     * @return list<string>
     */
    public function readCorporationRoles(Character $character): array
    {
        $token = $character->getEveToken();
        $characterId = $character->getEveCharacterId();
        if ($token === null) {
            throw new \UnexpectedValueException("Character {$characterId} has no token to read its corporation roles");
        }

        $roles = $this->esiClient->get("/characters/{$characterId}/roles/", $token)['roles'] ?? null;
        if (!is_array($roles) || !array_is_list($roles) || array_filter($roles, static fn (mixed $role): bool => !is_string($role)) !== []) {
            throw new \UnexpectedValueException("ESI roles response for character {$characterId} has no list of role names");
        }

        return $roles;
    }

    public function hasRole(Character $character, string $role): bool
    {
        $roles = $this->getCharacterRoles($character);

        return in_array($role, $roles, true);
    }

    public function canReadCorporationAssets(Character $character): bool
    {
        return $this->hasRole($character, 'Director');
    }
}
