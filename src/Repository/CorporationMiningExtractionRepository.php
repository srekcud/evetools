<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporationMiningExtraction;
use App\Entity\MiningOperation;
use App\Entity\MiningOperationRecurrence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporationMiningExtraction>
 */
class CorporationMiningExtractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporationMiningExtraction::class);
    }

    /**
     * @return list<CorporationMiningExtraction>
     */
    public function findArrivingBetween(int $corporationId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('extraction')
            ->where('extraction.corporationId = :corporationId')
            ->andWhere('extraction.chunkArrivalTime >= :from')
            ->andWhere('extraction.chunkArrivalTime < :until')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('extraction.chunkArrivalTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CorporationMiningExtraction>
     */
    public function findWithoutOccurrenceOf(MiningOperationRecurrence $recurrence, int $corporationId, int $structureId, \DateTimeImmutable $now): array
    {
        $alreadyLinked = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(MiningOperation::class, 'occurrence')
            ->where('occurrence.chunk = extraction')
            ->andWhere('occurrence.recurrence = :recurrence')
            ->getDQL();

        return $this->createQueryBuilder('extraction')
            ->where('extraction.corporationId = :corporationId')
            ->andWhere('extraction.structureId = :structureId')
            ->andWhere('extraction.extractionStartTime >= :createdAt')
            ->andWhere('extraction.chunkArrivalTime > :now')
            ->andWhere(sprintf('NOT EXISTS (%s)', $alreadyLinked))
            ->setParameter('corporationId', $corporationId)
            ->setParameter('structureId', $structureId)
            ->setParameter('createdAt', $recurrence->getCreatedAt())
            ->setParameter('now', $now)
            ->setParameter('recurrence', $recurrence)
            ->orderBy('extraction.chunkArrivalTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsForRefinery(int $corporationId, int $structureId): bool
    {
        return $this->findOneBy(['corporationId' => $corporationId, 'structureId' => $structureId]) !== null;
    }

    /**
     * @return list<array{corporationId: int, moonId: int, moonName: ?string}>
     */
    public function findDistinctMoons(): array
    {
        /** @var list<array{corporationId: numeric-string|int, moonId: numeric-string|int, moonName: ?string}> $rows */
        $rows = $this->createQueryBuilder('extraction')
            ->select('extraction.corporationId AS corporationId', 'extraction.moonId AS moonId', 'MAX(extraction.moonName) AS moonName')
            ->groupBy('extraction.corporationId', 'extraction.moonId')
            ->orderBy('extraction.corporationId', 'ASC')
            ->addOrderBy('extraction.moonId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['corporationId' => (int) $row['corporationId'], 'moonId' => (int) $row['moonId'], 'moonName' => $row['moonName']],
            $rows,
        );
    }
}
