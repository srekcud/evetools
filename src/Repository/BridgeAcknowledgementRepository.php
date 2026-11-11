<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BridgeAcknowledgement;
use App\Entity\User;
use App\Enum\BridgeTodoKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BridgeAcknowledgement>
 */
class BridgeAcknowledgementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BridgeAcknowledgement::class);
    }

    /**
     * @return BridgeAcknowledgement[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.itemKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndItemKey(User $user, BridgeTodoKey $itemKey): ?BridgeAcknowledgement
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.itemKey = :itemKey')
            ->setParameter('user', $user)
            ->setParameter('itemKey', $itemKey)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
