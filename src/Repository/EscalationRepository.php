<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Escalation;
use App\Entity\User;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
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
            $parsedVisibility = EscalationVisibility::tryFrom($visibility);
            if ($parsedVisibility !== null) {
                $qb->andWhere('e.visibility = :visibility')
                   ->setParameter('visibility', $parsedVisibility);
            }
        }

        if ($saleStatus !== null) {
            $parsedSaleStatus = EscalationSaleStatus::tryFrom($saleStatus);
            if ($parsedSaleStatus !== null) {
                $qb->andWhere('e.saleStatus = :saleStatus')
                   ->setParameter('saleStatus', $parsedSaleStatus);
            }
        }

        if ($activeOnly) {
            $qb->andWhere('e.expiresAt > :now')
               ->andWhere('e.saleStatus != :vendu')
               ->setParameter('now', new \DateTimeImmutable())
               ->setParameter('vendu', EscalationSaleStatus::Vendu);
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
            ->andWhere('e.saleStatus != :vendu')
            ->andWhere('e.expiresAt > :now')
            ->setParameter('corpId', $corporationId)
            ->setParameter('visibilities', [EscalationVisibility::Corp, EscalationVisibility::Alliance, EscalationVisibility::Public])
            ->setParameter('excludeUser', $excludeUser)
            ->setParameter('vendu', EscalationSaleStatus::Vendu)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Escalation[]
     */
    public function findByAlliance(int $allianceId, int $excludeCorporationId, User $excludeUser): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.allianceId = :allianceId')
            ->andWhere('e.corporationId != :excludeCorpId')
            ->andWhere('e.visibility IN (:visibilities)')
            ->andWhere('e.user != :excludeUser')
            ->andWhere('e.saleStatus != :vendu')
            ->andWhere('e.expiresAt > :now')
            ->setParameter('allianceId', $allianceId)
            ->setParameter('excludeCorpId', $excludeCorporationId)
            ->setParameter('visibilities', [EscalationVisibility::Alliance, EscalationVisibility::Public])
            ->setParameter('excludeUser', $excludeUser)
            ->setParameter('vendu', EscalationSaleStatus::Vendu)
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
            ->setParameter('visibility', EscalationVisibility::Public)
            ->setParameter('envente', EscalationSaleStatus::EnVente)
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
            $bmStatus = $row['bmStatus'] instanceof \App\Enum\EscalationBmStatus
                ? $row['bmStatus']->value
                : (string) $row['bmStatus'];
            $saleStatus = $row['saleStatus'] instanceof \App\Enum\EscalationSaleStatus
                ? $row['saleStatus']->value
                : (string) $row['saleStatus'];
            $counts[$bmStatus] = ($counts[$bmStatus] ?? 0) + $cnt;
            $counts[$saleStatus] = ($counts[$saleStatus] ?? 0) + $cnt;
        }

        /** @var array{total: int, nouveau: int, bm: int, envente: int, vendu: int} $counts */
        return $counts;
    }

    public function getTotalSoldValue(User $user): int
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.price)')
            ->where('e.user = :user')
            ->andWhere('e.saleStatus = :vendu')
            ->setParameter('user', $user)
            ->setParameter('vendu', EscalationSaleStatus::Vendu)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }
}
