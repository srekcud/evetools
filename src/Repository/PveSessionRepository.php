<?php

namespace App\Repository;

use App\Entity\PveSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PveSession>
 *
 * @method PveSession|null find($id, $lockMode = null, $lockVersion = null)
 * @method PveSession|null findOneBy(array $criteria, array $orderBy = null)
 * @method PveSession[]    findAll()
 * @method PveSession[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PveSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PveSession::class);
    }

    /**
     * Find the active session for a user
     */
    public function findActiveSession(User $user): ?PveSession
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', PveSession::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find sessions for a user within a date range
     *
     * @return PveSession[]
     */
    public function findByUserAndDateRange(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.startedAt >= :from')
            ->andWhere('s.startedAt <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find completed sessions for a user
     *
     * @return PveSession[]
     */
    public function findCompletedSessions(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', PveSession::STATUS_COMPLETED)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total session time in seconds for a date range
     */
    public function getTotalSessionTime(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): int {
        $sessions = $this->findByUserAndDateRange($user, $from, $to);
        $total = 0;
        foreach ($sessions as $session) {
            if ($session->getStatus() === PveSession::STATUS_COMPLETED) {
                $total += $session->getDurationSeconds();
            }
        }
        return $total;
    }

    /**
     * Get average ISK per hour across completed sessions
     */
    public function getAverageIskPerHour(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): float {
        $sessions = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :status')
            ->andWhere('s.startedAt >= :from')
            ->andWhere('s.startedAt <= :to')
            ->setParameter('user', $user)
            ->setParameter('status', PveSession::STATUS_COMPLETED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        if (empty($sessions)) {
            return 0.0;
        }

        $totalIskPerHour = 0.0;
        $count = 0;
        foreach ($sessions as $session) {
            $iskPerHour = $session->getIskPerHour();
            if ($iskPerHour > 0) {
                $totalIskPerHour += $iskPerHour;
                $count++;
            }
        }

        return $count > 0 ? $totalIskPerHour / $count : 0.0;
    }
}
