<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\Character;
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
        return new MiningLedgerReading([], null);
    }
}
