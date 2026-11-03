<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperationMember;
use Psr\Clock\ClockInterface;

final readonly class MiningPresenceResolver
{
    public const int ONLINE_WINDOW_SECONDS = 120;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function resolve(MiningOperationMember $member): ?MiningPresence
    {
        return null;
    }
}
