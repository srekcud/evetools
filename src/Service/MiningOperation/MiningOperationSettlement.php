<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class MiningOperationSettlement
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function mark(MiningPayment $payment, bool $paid, User $markedBy): void
    {
    }

    public function settle(MiningOperation $operation, User $settledBy): void
    {
    }
}
