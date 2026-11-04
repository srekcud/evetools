<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\Character;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;

final readonly class MiningCharacterShipReader
{
    public function __construct(
        private EsiClient $esiClient,
    ) {
    }

    public function read(Character $character): ?int
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return null;
        }

        try {
            $answer = $this->esiClient->get(sprintf('/characters/%d/ship/', $character->getEveCharacterId()), $token);
        } catch (EveAuthRequiredException|EsiApiException) {
            return null;
        }

        $shipTypeId = $answer['ship_type_id'] ?? null;

        return is_int($shipTypeId) ? $shipTypeId : null;
    }
}
