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

    /**
     * Contributions of a project, most recent first, with the contributing member, its user and main character,
     * the BOM item and the reviewer with its main character fetch-joined.
     *
     * @return GroupIndustryContribution[]
     */
    public function findByProjectForListing(GroupIndustryProject $project): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('contributor', 'contributorUser', 'contributorCharacter', 'contributorToken', 'bomItem', 'reviewer', 'reviewerCharacter', 'reviewerToken')
            ->join('c.member', 'contributor')
            ->join('contributor.user', 'contributorUser')
            // Character::eveToken is an inverse OneToOne: not joined, Doctrine would load it row by row
            ->leftJoin('contributorUser.mainCharacter', 'contributorCharacter')
            ->leftJoin('contributorCharacter.eveToken', 'contributorToken')
            ->leftJoin('c.bomItem', 'bomItem')
            ->leftJoin('c.reviewedBy', 'reviewer')
            ->leftJoin('reviewer.mainCharacter', 'reviewerCharacter')
            ->leftJoin('reviewerCharacter.eveToken', 'reviewerToken')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
