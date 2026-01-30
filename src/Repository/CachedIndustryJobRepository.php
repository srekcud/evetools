<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedIndustryJob>
 */
class CachedIndustryJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedIndustryJob::class);
    }

    public function findByJobId(int $jobId): ?CachedIndustryJob
    {
        return $this->findOneBy(['jobId' => $jobId]);
    }

    public function deleteByCharacter(Character $character): void
    {
        $this->createQueryBuilder('j')
            ->delete()
            ->where('j.character = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->execute();
    }

    /**
     * Find manufacturing/reaction jobs matching a blueprint type ID across all user's characters.
     * Activity IDs: 1 = Manufacturing, 9 = Reactions, 11 = Reverse Engineering
     *
     * @param int                     $blueprintTypeId Blueprint to match
     * @param array                   $characterIds    Characters to search
     * @param int|null                $targetRuns      If provided, prioritize jobs with matching runs
     * @param \DateTimeImmutable|null $startedAfter    Only match jobs started after this date
     *
     * @return CachedIndustryJob[]
     */
    public function findManufacturingJobsByBlueprint(
        int $blueprintTypeId,
        array $characterIds,
        ?int $targetRuns = null,
        ?\DateTimeImmutable $startedAfter = null,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->where('j.blueprintTypeId = :bpId')
            ->andWhere('j.activityId IN (1, 9, 11)')
            ->andWhere('j.character IN (:chars)')
            ->setParameter('bpId', $blueprintTypeId)
            ->setParameter('chars', $characterIds);

        if ($startedAfter !== null) {
            $qb->andWhere('j.startDate >= :startedAfter')
                ->setParameter('startedAfter', $startedAfter);
        }

        if ($targetRuns !== null) {
            // Sort by: exact match first, then runs >= target, then by start date
            $qb->addSelect('CASE
                WHEN j.runs = :targetRuns THEN 0
                WHEN j.runs >= :targetRuns THEN 1
                ELSE 2
            END AS HIDDEN priority')
                ->setParameter('targetRuns', $targetRuns)
                ->orderBy('priority', 'ASC')
                ->addOrderBy('j.startDate', 'DESC');
        } else {
            $qb->orderBy('j.startDate', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }
}
