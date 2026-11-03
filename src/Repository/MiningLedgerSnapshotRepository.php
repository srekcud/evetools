<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningLedgerSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MiningLedgerSnapshot>
 */
class MiningLedgerSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningLedgerSnapshot::class);
    }
}
