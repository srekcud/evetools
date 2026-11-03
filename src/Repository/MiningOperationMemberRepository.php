<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperationMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MiningOperationMember>
 */
class MiningOperationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningOperationMember::class);
    }
}
