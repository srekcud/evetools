<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedIndustryJob;
use App\Entity\UnassignedJob;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnassignedJob>
 */
class UnassignedJobRepository extends ServiceEntityRepository
{
    private const int MAXIMUM_STORED_ESI_JOB_ID = 2_147_483_647;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnassignedJob::class);
    }

    /**
     * @param list<int> $esiJobIds
     *
     * @return array<int, UnassignedJob>
     */
    public function findByEsiJobIds(array $esiJobIds): array
    {
        if ($esiJobIds === []) {
            return [];
        }

        $byEsiJobId = [];
        foreach ($this->createQueryBuilder('u')
            ->join('u.job', 'j')
            ->where('j.jobId IN (:ids)')
            ->setParameter('ids', $esiJobIds)
            ->getQuery()
            ->getResult() as $unassignedJob) {
            $byEsiJobId[$unassignedJob->getJob()->getJobId()] = $unassignedJob;
        }

        return $byEsiJobId;
    }

    /**
     * @return list<UnassignedJob>
     */
    public function findWithoutOutput(): array
    {
        return array_values($this->createQueryBuilder('u')
            ->join('u.job', 'j')
            ->where('j.status IN (:statuses)')
            ->setParameter('statuses', CachedIndustryJob::STATUSES_WITHOUT_OUTPUT)
            ->getQuery()
            ->getResult());
    }

    /**
     * @return list<UnassignedJob> the rows of the jobs of the user's characters, dismissed ones left out
     */
    public function findOfUser(User $user): array
    {
        return array_values($this->createQueryBuilder('u')
            ->join('u.job', 'j')
            ->join('j.character', 'c')
            ->where('c.user = :user')
            ->andWhere('u.dismissedAt IS NULL')
            ->orderBy('j.startDate', 'DESC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult());
    }

    public function findOneOfUser(User $user, int $esiJobId): ?UnassignedJob
    {
        if ($esiJobId > self::MAXIMUM_STORED_ESI_JOB_ID) {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->join('u.job', 'j')
            ->join('j.character', 'c')
            ->where('c.user = :user')
            ->andWhere('j.jobId = :esiJobId')
            ->setParameter('user', $user)
            ->setParameter('esiJobId', $esiJobId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<int>
     */
    public function findDismissedEsiJobIds(): array
    {
        return array_values(array_map(
            static fn (array $row): int => $row['jobId'],
            $this->createQueryBuilder('u')
                ->select('j.jobId AS jobId')
                ->join('u.job', 'j')
                ->where('u.dismissedAt IS NOT NULL')
                ->getQuery()
                ->getArrayResult(),
        ));
    }
}
