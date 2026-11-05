<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedCharacterStanding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedCharacterStanding>
 */
class CachedCharacterStandingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedCharacterStanding::class);
    }
}
