<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketPriceAlert>
 */
class MarketPriceAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketPriceAlert::class);
    }

    /**
     * @return MarketPriceAlert[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MarketPriceAlert[]
     */
    public function findActiveAlerts(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', AlertStatus::Active)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MarketPriceAlert[]
     */
    public function findTriggeredAlerts(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', AlertStatus::Triggered)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[]
     */
    public function getWatchedAlertTypeIds(): array
    {
        $result = $this->createQueryBuilder('a')
            ->select('DISTINCT a.typeId')
            ->where('a.status IN (:statuses)')
            ->setParameter('statuses', [AlertStatus::Active, AlertStatus::Triggered])
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $result);
    }
}
