<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReprocessingProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReprocessingProfile>
 */
class ReprocessingProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReprocessingProfile::class);
    }
}
