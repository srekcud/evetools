<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectActivity;
use App\Enum\ProjectActivityKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectActivity>
 */
class IndustryProjectActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectActivity::class);
    }

    /**
     * @return list<int>
     */
    public function recordedJobIdsOf(IndustryProject $project, ProjectActivityKind $kind): array
    {
        $recordedData = $this->createQueryBuilder('activity')
            ->select('activity.data')
            ->where('activity.project = :project')
            ->andWhere('activity.kind = :kind')
            ->setParameter('project', $project)
            ->setParameter('kind', $kind)
            ->getQuery()
            ->getSingleColumnResult();

        $jobIds = [];
        foreach ($recordedData as $data) {
            foreach ($data['jobIds'] as $jobId) {
                $jobIds[] = $jobId;
            }
        }

        return $jobIds;
    }

    /**
     * @return list<IndustryProjectActivity>
     */
    public function latestOf(IndustryProject $project, int $limit): array
    {
        return $this->findBy(['project' => $project], ['occurredAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
