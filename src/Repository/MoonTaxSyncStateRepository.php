<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonTaxSyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonTaxSyncState>
 */
class MoonTaxSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonTaxSyncState::class);
    }
}
