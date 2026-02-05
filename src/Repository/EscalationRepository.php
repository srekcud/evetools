<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Escalation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Escalation>
 */
class EscalationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Escalation::class);
    }

    /**
     * @return Escalation[]
     */
    public function findByUser(User $user, ?string $visibility = null, ?string $saleStatus = null, bool $activeOnly = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.expiresAt', 'ASC');

        if ($visibility !== null) {
            $qb->andWhere('e.visibility = :visibility')
               ->setParameter('visibility', $visibility);
        }

        if ($saleStatus !== null) {
            $qb->andWhere('e.saleStatus = :saleStatus')
               ->setParameter('saleStatus', $saleStatus);
        }

        if ($activeOnly) {
            $qb->andWhere('e.expiresAt > :now')
               ->andWhere('e.saleStatus != :vendu')
               ->setParameter('now', new \DateTimeImmutable())
               ->setParameter('vendu', Escalation::SALE_VENDU);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Escalation[]
     */
    public function findByCorporation(int $corporationId, User $excludeUser): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.corporationId = :corpId')
            ->andWhere('e.visibility IN (:visibilities)')
            ->andWhere('e.user != :excludeUser')
            ->andWhere('e.expiresAt > :now')
            ->setParameter('corpId', $corporationId)
            ->setParameter('visibilities', [Escalation::VISIBILITY_CORP, Escalation::VISIBILITY_PUBLIC])
            ->setParameter('excludeUser', $excludeUser)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Escalation[]
     */
    public function findPublic(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.visibility = :visibility')
            ->andWhere('e.saleStatus = :envente')
            ->andWhere('e.expiresAt > :now')
            ->setParameter('visibility', Escalation::VISIBILITY_PUBLIC)
            ->setParameter('envente', Escalation::SALE_ENVENTE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total: int, nouveau: int, bm: int, envente: int, vendu: int}
     */
    public function getCountsByUser(User $user): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('e.bmStatus, e.saleStatus, COUNT(e.id) as cnt')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->groupBy('e.bmStatus, e.saleStatus')
            ->getQuery()
            ->getResult();

        $counts = ['total' => 0, 'nouveau' => 0, 'bm' => 0, 'envente' => 0, 'vendu' => 0];
        foreach ($results as $row) {
            $cnt = (int) $row['cnt'];
            $counts['total'] += $cnt;
            $counts[$row['bmStatus']] = ($counts[$row['bmStatus']] ?? 0) + $cnt;
            $counts[$row['saleStatus']] = ($counts[$row['saleStatus']] ?? 0) + $cnt;
        }

        return $counts;
    }

    public function getTotalSoldValue(User $user): int
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.price)')
            ->where('e.user = :user')
            ->andWhere('e.saleStatus = :vendu')
            ->setParameter('user', $user)
            ->setParameter('vendu', Escalation::SALE_VENDU)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
