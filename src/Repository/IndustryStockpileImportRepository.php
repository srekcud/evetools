<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStockpileImport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryStockpileImport>
 */
class IndustryStockpileImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryStockpileImport::class);
    }

    /**
     * @return list<IndustryStockpileImport>
     */
    public function findByUserInCreationOrder(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function deleteAllForUser(User $user): void
    {
        $this->createQueryBuilder('i')
            ->delete()
            ->where('i.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function deleteAllForUserAndProduct(User $user, int $productTypeId): void
    {
        $this->createQueryBuilder('i')
            ->delete()
            ->where('i.user = :user')
            ->andWhere('i.productTypeId = :productTypeId')
            ->setParameter('user', $user)
            ->setParameter('productTypeId', $productTypeId)
            ->getQuery()
            ->execute();
    }
}
