<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\MapRegion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MapRegion>
 */
class MapRegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MapRegion::class);
    }
}
