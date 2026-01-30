<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\ChrRace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChrRace>
 */
class ChrRaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChrRace::class);
    }
}
