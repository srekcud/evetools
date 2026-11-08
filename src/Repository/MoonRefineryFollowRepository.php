<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonRefineryFollow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonRefineryFollow>
 */
class MoonRefineryFollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonRefineryFollow::class);
    }
}
