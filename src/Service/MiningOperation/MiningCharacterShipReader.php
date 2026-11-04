<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\Character;
use App\Service\ESI\EsiClient;

final readonly class MiningCharacterShipReader
{
    public function __construct(
        private EsiClient $esiClient,
    ) {
    }

    public function read(Character $character): ?int
    {
        return null;
    }
}
