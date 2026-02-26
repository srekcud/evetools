<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupIndustryProjectMember>
 */
class GroupIndustryProjectMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupIndustryProjectMember::class);
    }

    /**
     * Return project UUIDs (as strings) for all accepted memberships of a user.
     *
     * @return string[]
     */
    public function findAcceptedProjectIds(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.project) AS projectId')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', GroupMemberStatus::Accepted->value)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'projectId');
    }
}
