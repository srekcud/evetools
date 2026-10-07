<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupIndustryProject;
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

    /**
     * Accepted memberships of a user, most recent first, with everything the project listing maps:
     * the project, its owner and the owner's main character are fetch-joined, the project items and
     * BOM items are batch-loaded (one query per collection, to avoid a row explosion).
     *
     * @return GroupIndustryProjectMember[]
     */
    public function findAcceptedWithProjectsByUser(User $user): array
    {
        /** @var GroupIndustryProjectMember[] $memberships */
        $memberships = $this->createQueryBuilder('m')
            ->addSelect('p', 'owner', 'ownerCharacter', 'ownerToken')
            ->join('m.project', 'p')
            ->join('p.owner', 'owner')
            // Character::eveToken is an inverse OneToOne: not joined, Doctrine would load it row by row
            ->leftJoin('owner.mainCharacter', 'ownerCharacter')
            ->leftJoin('ownerCharacter.eveToken', 'ownerToken')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', GroupMemberStatus::Accepted->value)
            ->orderBy('m.joinedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $projects = array_map(static fn (GroupIndustryProjectMember $m): GroupIndustryProject => $m->getProject(), $memberships);
        if ($projects !== []) {
            $this->loadProjectCollection($projects, 'items');
            $this->loadProjectCollection($projects, 'bomItems');
        }

        return $memberships;
    }

    /**
     * Number of accepted members per project, keyed by project UUID (RFC 4122).
     *
     * @param GroupIndustryProject[] $projects
     *
     * @return array<string, int>
     */
    public function countAcceptedByProject(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.project) AS projectId', 'COUNT(m.id) AS membersCount')
            ->andWhere('m.project IN (:projects)')
            ->andWhere('m.status = :status')
            ->setParameter('projects', $projects)
            ->setParameter('status', GroupMemberStatus::Accepted->value)
            ->groupBy('m.project')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['projectId']] = (int) $row['membersCount'];
        }

        return $counts;
    }

    /**
     * Initializes a collection of the given projects in a single fetch-join query.
     *
     * @param GroupIndustryProject[] $projects
     */
    private function loadProjectCollection(array $projects, string $collection): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->select('p', 'c')
            ->from(GroupIndustryProject::class, 'p')
            ->leftJoin('p.'.$collection, 'c')
            ->andWhere('p IN (:projects)')
            ->setParameter('projects', $projects)
            ->getQuery()
            ->getResult();
    }
}
