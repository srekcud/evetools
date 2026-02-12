<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanetaryPin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanetaryPin>
 */
class PlanetaryPinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetaryPin::class);
    }
}
