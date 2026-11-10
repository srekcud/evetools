<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectJobMatch>
 */
class IndustryProjectJobMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectJobMatch::class);
    }

    /**
     * @param int[] $esiJobIds
     *
     * @return IndustryProjectJobMatch[]
     */
    public function findByEsiJobIds(array $esiJobIds): array
    {
        if ($esiJobIds === []) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->where('m.esiJobId IN (:ids)')
            ->setParameter('ids', $esiJobIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[]
     */
    public function findEsiJobIdsMatchedToOtherProjects(IndustryProject $project): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.esiJobId')
            ->join('m.bomLine', 'l')
            ->where('l.project != :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'esiJobId'));
    }

    /**
     * @param int[] $esiJobIds
     *
     * @return array<int, IndustryProject>
     */
    public function findVisibleProjectsByEsiJobIds(User $user, array $esiJobIds): array
    {
        return [];
    }
}
