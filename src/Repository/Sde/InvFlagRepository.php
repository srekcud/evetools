<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\InvFlag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvFlag>
 */
class InvFlagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvFlag::class);
    }
}
