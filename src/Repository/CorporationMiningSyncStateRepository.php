<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporationMiningSyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporationMiningSyncState>
 */
class CorporationMiningSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporationMiningSyncState::class);
    }
}
