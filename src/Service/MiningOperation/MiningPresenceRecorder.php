<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class MiningPresenceRecorder
{
    public function __construct(
        private MiningPresenceResolver $resolver,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return bool true when the participant was not online before this heartbeat
     */
    public function record(MiningOperation $operation, User $user): bool
    {
        return false;
    }
}
