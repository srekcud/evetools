<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * @return PushSubscription[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndEndpoint(User $user, string $endpoint): ?PushSubscription
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.user = :user')
            ->andWhere('ps.endpoint = :endpoint')
            ->setParameter('user', $user)
            ->setParameter('endpoint', $endpoint)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteByUser(User $user): int
    {
        return $this->createQueryBuilder('ps')
            ->delete()
            ->where('ps.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
