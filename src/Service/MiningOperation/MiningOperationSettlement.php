<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Enum\MiningOperationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class MiningOperationSettlement
{
    private const string PAYMENTS_NOT_MARKABLE_MESSAGE = 'Payments can only be marked while the shares are frozen';
    private const string NOT_SETTLEABLE_MESSAGE = 'Only an operation with frozen shares can be settled';
    private const string PAYMENTS_LEFT_MESSAGE = 'Every payment must be marked paid before settling: %d left';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function mark(MiningPayment $payment, bool $paid, User $markedBy): void
    {
        if ($payment->getOperation()->getStatus() !== MiningOperationStatus::Shares) {
            throw new BadRequestHttpException(self::PAYMENTS_NOT_MARKABLE_MESSAGE);
        }
        if ($payment->isPaid() === $paid) {
            return;
        }

        if ($paid) {
            $payment->markPaid($markedBy, $markedBy->getMainCharacter()?->getName(), $this->clock->now());
        } else {
            $payment->unmarkPaid();
        }
        $this->entityManager->flush();
    }

    public function settle(MiningOperation $operation, User $settledBy): void
    {
        if ($operation->getStatus() !== MiningOperationStatus::Shares) {
            throw new BadRequestHttpException(self::NOT_SETTLEABLE_MESSAGE);
        }

        $unpaidCount = count(array_filter(
            $operation->getPayments()->toArray(),
            static fn (MiningPayment $payment): bool => !$payment->isPaid(),
        ));
        if ($unpaidCount > 0) {
            throw new BadRequestHttpException(sprintf(self::PAYMENTS_LEFT_MESSAGE, $unpaidCount));
        }

        $operation->settle($this->clock->now(), $settledBy->getMainCharacter()?->getName());
        $this->entityManager->flush();
    }
}
