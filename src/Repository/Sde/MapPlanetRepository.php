<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\MapPlanet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MapPlanet>
 */
class MapPlanetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MapPlanet::class);
    }
}
