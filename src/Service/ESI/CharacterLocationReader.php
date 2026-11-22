<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;

readonly class CharacterLocationReader
{
    public function __construct(
        private EsiClient $esiClient,
    ) {
    }

    public function read(Character $character): ?CharacterLocation
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return null;
        }

        $location = $this->fetch(sprintf('/characters/%d/location/', $character->getEveCharacterId()), $token);
        $solarSystemId = $location['solar_system_id'] ?? null;
        if (!is_int($solarSystemId)) {
            return null;
        }

        $online = $this->fetch(sprintf('/characters/%d/online/', $character->getEveCharacterId()), $token);
        $isOnline = $online['online'] ?? null;

        return new CharacterLocation(
            $solarSystemId,
            $this->integerOrNull($location, 'station_id'),
            $this->integerOrNull($location, 'structure_id'),
            is_bool($isOnline) ? $isOnline : null,
            $this->dateOrNull($online, 'last_login'),
        );
    }

    /**
     * @return array<mixed>
     */
    private function fetch(string $endpoint, EveToken $token): array
    {
        try {
            return $this->esiClient->getWithCache($endpoint, $token);
        } catch (EveAuthRequiredException|EsiApiException) {
            return [];
        }
    }

    /**
     * @param array<mixed> $answer
     */
    private function integerOrNull(array $answer, string $key): ?int
    {
        $value = $answer[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<mixed> $answer
     */
    private function dateOrNull(array $answer, string $key): ?\DateTimeImmutable
    {
        $value = $answer[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}
