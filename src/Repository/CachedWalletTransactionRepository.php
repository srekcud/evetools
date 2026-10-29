<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\GroupIndustrySale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
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

    /**
     * @param Character[] $characters
     * @param int[] $typeIds
     * @return CachedWalletTransaction[]
     */
    public function findUnlinkedSaleTransactions(array $characters, array $typeIds, \DateTimeImmutable $since): array
    {
        if ($characters === [] || $typeIds === []) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('c', 'token')
            ->innerJoin('t.character', 'c')
            ->leftJoin('c.eveToken', 'token')
            ->leftJoin(GroupIndustrySale::class, 's', Join::WITH, 's.walletTransaction = t')
            ->where('t.character IN (:characters)')
            ->andWhere('t.isBuy = false')
            ->andWhere('t.typeId IN (:typeIds)')
            ->andWhere('t.date >= :since')
            ->andWhere('s.id IS NULL')
            ->setParameter('characters', $characters)
            ->setParameter('typeIds', $typeIds)
            ->setParameter('since', $since)
            ->orderBy('t.date', 'DESC')
            ->getQuery()
            ->getResult();
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
