<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanetaryRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanetaryRoute>
 */
class PlanetaryRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetaryRoute::class);
    }
}
