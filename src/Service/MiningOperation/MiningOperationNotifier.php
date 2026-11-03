<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Service\Notification\NotificationDispatcher;

final readonly class MiningOperationNotifier
{
    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public function endLedgerSettled(MiningOperation $operation): void
    {
    }

    public function sharesFrozen(MiningOperation $operation): void
    {
    }

    public function paymentMarkedPaid(MiningPayment $payment, User $markedBy): void
    {
    }

    public function joinRequested(MiningOperation $operation, MiningOperationMember $member): void
    {
    }
}
