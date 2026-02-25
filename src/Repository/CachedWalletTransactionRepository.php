<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedWalletTransaction>
 */
class CachedWalletTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedWalletTransaction::class);
    }

    /**
     * Find all buy transactions matching given type IDs.
     *
     * @param int[] $typeIds
     * @return CachedWalletTransaction[]
     */
    public function findBuysByTypeIds(Character $character, array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->where('t.character = :character')
            ->andWhere('t.typeId IN (:typeIds)')
            ->andWhere('t.isBuy = true')
            ->setParameter('character', $character)
            ->setParameter('typeIds', $typeIds)
            ->orderBy('t.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByTransactionId(int $transactionId): ?CachedWalletTransaction
    {
        return $this->findOneBy(['transactionId' => $transactionId]);
    }

    /**
     * Check which transaction IDs already exist in the database.
     *
     * @param int[] $transactionIds
     * @return int[] existing transaction IDs
     */
    public function findExistingTransactionIds(array $transactionIds): array
    {
        if (empty($transactionIds)) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->select('t.transactionId')
            ->where('t.transactionId IN (:ids)')
            ->setParameter('ids', $transactionIds)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
