<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperationMember;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningPresenceState;
use Psr\Clock\ClockInterface;

final readonly class MiningPresenceResolver
{
    public const int ONLINE_WINDOW_SECONDS = 120;

    private const int SECONDS_PER_MINUTE = 60;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function resolve(MiningOperationMember $member): ?MiningPresence
    {
        if ($member->getStatus() !== MiningOperationMemberStatus::Accepted || $member->getOperation()->getStatus() !== MiningOperationStatus::InProgress) {
            return null;
        }
        if ($member->getDepartedAt() !== null) {
            return new MiningPresence(MiningPresenceState::Left, null);
        }

        $lastSeenAt = $member->getLastSeenAt();
        if ($lastSeenAt === null) {
            return new MiningPresence(MiningPresenceState::Unknown, null);
        }

        $idleSeconds = $this->clock->now()->getTimestamp() - $lastSeenAt->getTimestamp();
        if ($idleSeconds <= self::ONLINE_WINDOW_SECONDS) {
            return new MiningPresence(MiningPresenceState::Online, 0);
        }

        return new MiningPresence(MiningPresenceState::Afk, intdiv($idleSeconds, self::SECONDS_PER_MINUTE));
    }
}
