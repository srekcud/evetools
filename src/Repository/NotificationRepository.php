<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findPaginated(User $user, int $page, ?string $category = null, ?bool $isRead = null, int $perPage = 20): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($category !== null) {
            $qb->andWhere('n.category = :category')
               ->setParameter('category', $category);
        }

        if ($isRead !== null) {
            $qb->andWhere('n.isRead = :isRead')
               ->setParameter('isRead', $isRead);
        }

        return $qb->getQuery()->getResult();
    }

    public function countUnread(User $user): int
    {
        $result = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function markAllAsRead(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', 'true')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
