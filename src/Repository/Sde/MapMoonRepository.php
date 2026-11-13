<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\MapMoon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MapMoon>
 */
class MapMoonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MapMoon::class);
    }
}
