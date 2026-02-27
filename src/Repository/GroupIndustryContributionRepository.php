<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupIndustryContribution>
 */
class GroupIndustryContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupIndustryContribution::class);
    }

    /**
     * @return GroupIndustryContribution[]
     */
    public function findApprovedByProject(GroupIndustryProject $project): array
    {
        return $this->findBy([
            'project' => $project,
            'status' => ContributionStatus::Approved,
        ]);
    }

    public function countByMember(GroupIndustryProjectMember $member): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.member = :member')
            ->setParameter('member', $member)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find an existing contribution for a member on a specific BOM item with a given type and matching statuses.
     *
     * @param ContributionStatus[] $statuses
     */
    public function findByMemberBomItemAndType(
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        ContributionType $type,
        array $statuses,
    ): ?GroupIndustryContribution {
        if (empty($statuses)) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.member = :member')
            ->andWhere('c.bomItem = :bomItem')
            ->andWhere('c.type = :type')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('member', $member)
            ->setParameter('bomItem', $bomItem)
            ->setParameter('type', $type)
            ->setParameter('statuses', array_map(static fn (ContributionStatus $s) => $s->value, $statuses))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
