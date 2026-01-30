<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\ChrFaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChrFaction>
 */
class ChrFactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChrFaction::class);
    }
}
