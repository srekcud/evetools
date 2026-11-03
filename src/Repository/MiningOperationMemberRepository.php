<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
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

    public function findOwnershipSuccessor(MiningOperation $operation, User $leavingOwner): ?MiningOperationMember
    {
        return $this->createQueryBuilder('participant')
            ->addSelect('CASE WHEN participant.role = :admin THEN 0 ELSE 1 END AS HIDDEN rolePriority')
            ->andWhere('participant.operation = :operation')
            ->andWhere('participant.status = :accepted')
            ->andWhere('participant.user != :leavingOwner')
            ->setParameter('admin', MiningOperationMemberRole::Admin->value)
            ->setParameter('operation', $operation)
            ->setParameter('accepted', MiningOperationMemberStatus::Accepted->value)
            ->setParameter('leavingOwner', $leavingOwner)
            ->orderBy('rolePriority', 'ASC')
            ->addOrderBy('participant.joinedAt', 'ASC')
            ->addOrderBy('participant.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
