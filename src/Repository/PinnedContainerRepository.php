<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PinnedContainer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PinnedContainer>
 */
class PinnedContainerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PinnedContainer::class);
    }

    /**
     * @return list<PinnedContainer>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('pin')
            ->where('pin.user = :user')
            ->setParameter('user', $user)
            ->orderBy('pin.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndItemId(User $user, int $itemId): ?PinnedContainer
    {
        return $this->createQueryBuilder('pin')
            ->where('pin.user = :user')
            ->andWhere('pin.itemId = :itemId')
            ->setParameter('user', $user)
            ->setParameter('itemId', $itemId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
