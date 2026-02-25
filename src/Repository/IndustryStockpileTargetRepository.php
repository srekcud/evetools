<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryStockpileTarget>
 */
class IndustryStockpileTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryStockpileTarget::class);
    }

    /**
     * @return IndustryStockpileTarget[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findByUserAndTypeId(User $user, int $typeId): ?IndustryStockpileTarget
    {
        return $this->findOneBy(['user' => $user, 'typeId' => $typeId]);
    }

    public function deleteAllForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->delete()
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
