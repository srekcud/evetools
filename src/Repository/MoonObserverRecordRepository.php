<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonObserverRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonObserverRecord>
 */
class MoonObserverRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonObserverRecord::class);
    }
}
