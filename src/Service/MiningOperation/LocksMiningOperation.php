<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use Doctrine\DBAL\LockMode;

trait LocksMiningOperation
{
    private function underLockOf(MiningOperation $operation, callable $action): void
    {
        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->refresh($operation, LockMode::PESSIMISTIC_WRITE);
            $action();
            $this->entityManager->commit();
        } catch (\Throwable $failure) {
            $this->entityManager->rollback();

            throw $failure;
        }
    }
}
