<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MiningSite;
use App\Entity\MiningSiteScan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MiningSiteScan>
 */
class MiningSiteScanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiningSiteScan::class);
    }

    /**
     * @return list<MiningSiteScan>
     */
    public function findBySiteChronological(MiningSite $site): array
    {
        return $this->findBy(['site' => $site], ['scannedAt' => 'ASC']);
    }

    public function findFirstOf(MiningSite $site): ?MiningSiteScan
    {
        return $this->findOneBy(['site' => $site], ['scannedAt' => 'ASC']);
    }

    public function findLastOf(MiningSite $site): ?MiningSiteScan
    {
        return $this->findOneBy(['site' => $site], ['scannedAt' => 'DESC']);
    }

    public function findOfSite(MiningSite $site, Uuid $id): ?MiningSiteScan
    {
        return $this->findOneBy(['id' => $id, 'site' => $site]);
    }

    public function countUpTo(MiningSite $site, \DateTimeImmutable $instant): int
    {
        return (int) $this->createQueryBuilder('scan')
            ->select('COUNT(scan.id)')
            ->where('scan.site = :site')
            ->andWhere('scan.scannedAt <= :instant')
            ->setParameter('site', $site)
            ->setParameter('instant', $instant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function existsAt(MiningSite $site, \DateTimeImmutable $instant): bool
    {
        return $this->findOneBy(['site' => $site, 'scannedAt' => $instant]) !== null;
    }
}
