<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\Character;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;

final readonly class MiningLedgerReader
{
    public function __construct(
        private EsiClient $esiClient,
        private TokenManager $tokenManager,
    ) {
    }

    public function read(Character $character): MiningLedgerReading
    {
        $token = $character->getEveToken() ?? throw new EveAuthRequiredException((string) $character->getEveCharacterId());
        $this->tokenManager->getValidAccessToken($token);

        $response = $this->esiClient->getPaginatedWithExpiry(sprintf('/characters/%d/mining/', $character->getEveCharacterId()), $token);

        return new MiningLedgerReading(
            array_map(static fn (array $row): MiningLedgerEntry => new MiningLedgerEntry(
                new \DateTimeImmutable($row['date'], new \DateTimeZone('UTC')),
                $row['type_id'],
                $row['solar_system_id'],
                $row['quantity'],
            ), array_values($response->items)),
            $response->expiresAt,
        );
    }
}
