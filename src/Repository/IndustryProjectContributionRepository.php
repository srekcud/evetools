<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<IndustryProjectContribution>
 */
class IndustryProjectContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectContribution::class);
    }

    /**
     * @return IndustryProjectContribution[]
     */
    public function findApprovedByProject(IndustryProject $project): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('contributor', 'contributorUser', 'contributorCharacter', 'contributorToken')
            ->join('c.member', 'contributor')
            ->leftJoin('contributor.user', 'contributorUser')
            ->leftJoin('contributorUser.mainCharacter', 'contributorCharacter')
            ->leftJoin('contributorCharacter.eveToken', 'contributorToken')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', ContributionStatus::Approved->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved contribution value per project, keyed by project UUID (RFC 4122); 0.0 for a project without any.
     *
     * @param list<IndustryProject> $projects
     *
     * @return array<string, float>
     */
    public function sumApprovedValueByProject(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.project) AS projectId', 'SUM(c.estimatedValue) AS approvedValue')
            ->andWhere('c.project IN (:projects)')
            ->andWhere('c.status = :status')
            ->setParameter('projects', $projects)
            ->setParameter('status', ContributionStatus::Approved->value)
            ->groupBy('c.project')
            ->getQuery()
            ->getScalarResult();

        $values = [];
        foreach ($projects as $project) {
            $values[(string) $project->getId()] = 0.0;
        }
        foreach ($rows as $row) {
            $values[(string) $row['projectId']] = (float) $row['approvedValue'];
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function findContributedBomItemIds(IndustryProject $project): array
    {
        $bomItemIds = $this->createQueryBuilder('c')
            ->select('DISTINCT IDENTITY(c.bomItem)')
            ->andWhere('c.project = :project')
            ->andWhere('c.bomItem IS NOT NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): string => Uuid::fromString((string) $id)->toRfc4122(), $bomItemIds));
    }

    /**
     * Find an existing contribution for a member on a specific BOM item with a given type and matching statuses.
     *
     * @param ContributionStatus[] $statuses
     */
    public function findByMemberBomItemAndType(
        IndustryProjectParticipant $member,
        IndustryProjectBomLine $bomItem,
        ContributionType $type,
        array $statuses,
    ): ?IndustryProjectContribution {
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
     * @return IndustryProjectContribution[]
     */
    public function findByProjectForListing(IndustryProject $project): array
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
