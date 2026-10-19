<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStockpileImport;
use App\Entity\User;

class IndustryStockpileImportRepository
{
    /**
     * @return list<IndustryStockpileImport>
     */
    public function findByUserInCreationOrder(User $user): array
    {
        return [];
    }

    public function deleteAllForUser(User $user): void
    {
    }

    public function deleteAllForUserAndProduct(User $user, int $productTypeId): void
    {
    }
}
