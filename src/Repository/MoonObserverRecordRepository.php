<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonObserverRecord;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonObserverRecord>
 */
class MoonObserverRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonObserverRecord::class);
    }

    /**
     * @return list<MoonObserverRecord>
     */
    public function findOfObserverSince(int $corporationId, int $observerId, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('record')
            ->where('record.corporationId = :corporationId')
            ->andWhere('record.observerId = :observerId')
            ->andWhere('record.lastUpdated >= :since')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('observerId', $observerId)
            ->setParameter('since', $since, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MoonObserverRecord>
     */
    public function findOfObserverBefore(int $corporationId, int $observerId, \DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('record')
            ->where('record.corporationId = :corporationId')
            ->andWhere('record.observerId = :observerId')
            ->andWhere('record.lastUpdated < :before')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('observerId', $observerId)
            ->setParameter('before', $before, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int>|null $characterIds every character when null
     *
     * @return list<MoonObserverRecord>
     */
    public function findOfObserverBetween(int $corporationId, int $observerId, \DateTimeImmutable $start, \DateTimeImmutable $end, ?array $characterIds): array
    {
        $query = $this->createQueryBuilder('record')
            ->where('record.corporationId = :corporationId')
            ->andWhere('record.observerId = :observerId')
            ->andWhere('record.lastUpdated >= :start')
            ->andWhere('record.lastUpdated < :end')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('observerId', $observerId)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE);
        if ($characterIds !== null) {
            $query->andWhere('record.characterId IN (:characterIds)')->setParameter('characterIds', $characterIds);
        }

        return $query->getQuery()->getResult();
    }
}
