<?php

declare(strict_types=1);

namespace App\Service\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

class CharacterSyncIsolation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function run(\Closure $work): void
    {
        $this->connection->beginTransaction();

        try {
            $work();
            $this->connection->commit();
        } catch (\Throwable $throwable) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $this->registry->resetManager();

            throw $throwable;
        }
    }
}
