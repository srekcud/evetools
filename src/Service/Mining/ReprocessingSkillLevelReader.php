<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Enum\ReprocessingFailure;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;

final readonly class ReprocessingSkillLevelReader
{
    private const string SKILLS_SCOPE = 'esi-skills.read_skills.v1';

    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
    ) {
    }

    public function read(Character $character): ReprocessingSkillLevels|ReprocessingFailure
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return ReprocessingFailure::MissingToken;
        }
        if (!$token->hasScope(self::SKILLS_SCOPE)) {
            return ReprocessingFailure::MissingScope;
        }

        try {
            $skillsResponse = $this->fetchSkills($character, $token);
        } catch (EveAuthRequiredException) {
            return ReprocessingFailure::MissingToken;
        } catch (EsiApiException) {
            return ReprocessingFailure::EsiError;
        }

        $activeLevelsBySkillId = $this->activeLevelsBySkillId($skillsResponse);

        return $activeLevelsBySkillId === null
            ? ReprocessingFailure::EsiError
            : new ReprocessingSkillLevels($activeLevelsBySkillId);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSkills(Character $character, EveToken $token): array
    {
        $this->tokenManager->getValidAccessToken($token);

        return $this->esiClient->get("/characters/{$character->getEveCharacterId()}/skills/", $token);
    }

    /**
     * @param array<string, mixed> $skillsResponse
     *
     * @return array<int, int>|null
     */
    private function activeLevelsBySkillId(array $skillsResponse): ?array
    {
        $skills = $skillsResponse['skills'] ?? null;
        if (!is_array($skills) || !array_is_list($skills)) {
            return null;
        }

        $activeLevelsBySkillId = [];
        foreach ($skills as $skill) {
            if (!is_array($skill) || !is_int($skill['skill_id'] ?? null) || !is_int($skill['active_skill_level'] ?? null)) {
                return null;
            }
            $activeLevelsBySkillId[$skill['skill_id']] = $skill['active_skill_level'];
        }

        return $activeLevelsBySkillId;
    }
}
