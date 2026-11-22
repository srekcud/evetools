<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProjectPurchase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<IndustryProjectPurchase>
 */
class IndustryProjectPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectPurchase::class);
    }

    public function linkedQuantityOf(CachedWalletTransaction $transaction): int
    {
        return $this->linkedQuantitiesOf([$transaction])[$transaction->getId()->toRfc4122()];
    }

    /**
     * @param list<CachedWalletTransaction> $transactions
     *
     * @return array<string, int>
     */
    public function linkedQuantitiesOf(array $transactions): array
    {
        if ($transactions === []) {
            return [];
        }

        $quantities = [];
        foreach ($transactions as $transaction) {
            $quantities[$transaction->getId()->toRfc4122()] = 0;
        }

        $rows = $this->createQueryBuilder('purchase')
            ->select('IDENTITY(purchase.transaction) AS transactionId', 'SUM(purchase.quantity) AS linkedQuantity')
            ->where('purchase.transaction IN (:transactions)')
            ->setParameter('transactions', array_map(static fn (CachedWalletTransaction $transaction): Uuid => $transaction->getId(), $transactions))
            ->groupBy('purchase.transaction')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $quantities[Uuid::fromString((string) $row['transactionId'])->toRfc4122()] = (int) $row['linkedQuantity'];
        }

        return $quantities;
    }
}
