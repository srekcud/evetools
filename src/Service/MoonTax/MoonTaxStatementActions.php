<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Entity\MoonTaxStatement;
use App\Entity\User;
use App\Repository\MoonTaxStatementRepository;
use App\Service\UuidOrNotFound;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MoonTaxStatementActions
{
    private const string NOT_FOUND_MESSAGE = 'Moon tax statement not found';

    public function __construct(
        private MoonTaxStatementRepository $statementRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function statementOf(int $corporationId, string $identifier): MoonTaxStatement
    {
        return $this->statementRepository->findOneBy(['id' => UuidOrNotFound::of($identifier, self::NOT_FOUND_MESSAGE), 'corporationId' => $corporationId])
            ?? throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
    }

    public function markPaid(MoonTaxStatement $statement, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($statement, $actor): void {
            $this->entityManager->refresh($statement, LockMode::PESSIMISTIC_WRITE);
            $this->applyMarkPaid($statement, $actor);
        });
    }

    public function cancel(MoonTaxStatement $statement, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($statement, $actor): void {
            $this->entityManager->refresh($statement, LockMode::PESSIMISTIC_WRITE);
            $this->applyCancel($statement, $actor);
        });
    }

    private function applyMarkPaid(MoonTaxStatement $statement, User $actor): void
    {
        if ($statement->getCancelledAt() !== null) {
            throw new ConflictHttpException('A cancelled moon tax statement cannot be marked as paid');
        }
        if ($statement->getMarkedPaidAt() !== null) {
            return;
        }

        $statement->setMarkedPaidAt($this->clock->now());
        $this->trace($statement, $actor);
    }

    private function applyCancel(MoonTaxStatement $statement, User $actor): void
    {
        if ($statement->getMarkedPaidAt() !== null) {
            throw new ConflictHttpException('A moon tax statement marked as paid cannot be cancelled');
        }
        if ($statement->getCancelledAt() !== null) {
            return;
        }

        $statement->setCancelledAt($this->clock->now());
        $this->trace($statement, $actor);
    }

    private function trace(MoonTaxStatement $statement, User $actor): void
    {
        $statement->setActedBy($actor)->setActedAt($this->clock->now());
        $this->entityManager->flush();
    }
}
