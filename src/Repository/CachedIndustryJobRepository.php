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
     * Find manufacturing/reaction jobs matching a blueprint type ID with EXACT run count.
     * Activity IDs: 1 = Manufacturing, 9 = Reactions, 11 = Reverse Engineering
     *
     * @param int                     $blueprintTypeId Blueprint to match
     * @param list<\Symfony\Component\Uid\Uuid|int|null> $characterIds Characters to search
     * @param int|null                $targetRuns      If provided, only return jobs with exact run match
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

        // STRICT: only return exact matches
        if ($targetRuns !== null) {
            $qb->andWhere('j.runs = :targetRuns')
                ->setParameter('targetRuns', $targetRuns);
        }

        $qb->orderBy('j.startDate', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find jobs with same blueprint but DIFFERENT run count (for warning).
     *
     * @param int                     $blueprintTypeId Blueprint to match
     * @param list<\Symfony\Component\Uid\Uuid|int|null> $characterIds Characters to search
     * @param int                     $targetRuns      Run count to exclude (we want different runs)
     * @param \DateTimeImmutable|null $startedAfter    Only match jobs started after this date
     *
     * @return CachedIndustryJob[]
     */
    public function findSimilarJobsWithDifferentRuns(
        int $blueprintTypeId,
        array $characterIds,
        int $targetRuns,
        ?\DateTimeImmutable $startedAfter = null,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->where('j.blueprintTypeId = :bpId')
            ->andWhere('j.activityId IN (1, 9, 11)')
            ->andWhere('j.character IN (:chars)')
            ->andWhere('j.runs != :targetRuns')
            ->setParameter('bpId', $blueprintTypeId)
            ->setParameter('chars', $characterIds)
            ->setParameter('targetRuns', $targetRuns);

        if ($startedAfter !== null) {
            $qb->andWhere('j.startDate >= :startedAfter')
                ->setParameter('startedAfter', $startedAfter);
        }

        $qb->orderBy('j.startDate', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find all jobs matching any of the given blueprint type IDs for the given characters.
     *
     * @param int[]                    $blueprintTypeIds
     * @param list<\Symfony\Component\Uid\Uuid|int|null> $characterIds
     * @param \DateTimeImmutable|null  $startedAfter
     *
     * @return CachedIndustryJob[]
     */
    public function findByBlueprintsAndCharacters(
        array $blueprintTypeIds,
        array $characterIds,
        ?\DateTimeImmutable $startedAfter = null,
    ): array {
        $qb = $this->createQueryBuilder('j')
            ->where('j.blueprintTypeId IN (:bpIds)')
            ->andWhere('j.activityId IN (1, 9, 11)')
            ->andWhere('j.character IN (:chars)')
            ->setParameter('bpIds', $blueprintTypeIds)
            ->setParameter('chars', $characterIds);

        if ($startedAfter !== null) {
            $qb->andWhere('j.startDate >= :startedAfter')
                ->setParameter('startedAfter', $startedAfter);
        }

        $qb->orderBy('j.startDate', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
