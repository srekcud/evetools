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
        return $this->findBy(['site' => $site, 'hiddenAt' => null], ['scannedAt' => 'ASC']);
    }

    public function findFirstOf(MiningSite $site): ?MiningSiteScan
    {
        return $this->findOneBy(['site' => $site, 'hiddenAt' => null], ['scannedAt' => 'ASC']);
    }

    public function findLastOf(MiningSite $site): ?MiningSiteScan
    {
        return $this->findOneBy(['site' => $site, 'hiddenAt' => null], ['scannedAt' => 'DESC']);
    }

    public function findOfSite(MiningSite $site, Uuid $id): ?MiningSiteScan
    {
        return $this->findOneBy(['id' => $id, 'site' => $site, 'hiddenAt' => null]);
    }

    public function countUpTo(MiningSite $site, \DateTimeImmutable $instant): int
    {
        return (int) $this->createQueryBuilder('scan')
            ->select('COUNT(scan.id)')
            ->where('scan.site = :site')
            ->andWhere('scan.hiddenAt IS NULL')
            ->andWhere('scan.scannedAt <= :instant')
            ->setParameter('site', $site)
            ->setParameter('instant', $instant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countVisibleOf(MiningSite $site): int
    {
        return (int) $this->createQueryBuilder('scan')
            ->select('COUNT(scan.id)')
            ->where('scan.site = :site')
            ->andWhere('scan.hiddenAt IS NULL')
            ->setParameter('site', $site)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function existsAt(MiningSite $site, \DateTimeImmutable $instant): bool
    {
        return $this->findOneBy(['site' => $site, 'scannedAt' => $instant]) !== null;
    }

    /**
     * @param list<MiningSite> $sites
     *
     * @return array<string, MiningSiteScan>
     */
    public function findFirstVisibleOfSites(array $sites): array
    {
        return $this->findVisibleBoundOfSites($sites, 'MIN');
    }

    /**
     * @param list<MiningSite> $sites
     *
     * @return array<string, MiningSiteScan>
     */
    public function findLastVisibleOfSites(array $sites): array
    {
        return $this->findVisibleBoundOfSites($sites, 'MAX');
    }

    /**
     * @param list<MiningSite> $sites
     *
     * @return array<string, int>
     */
    public function countVisibleOfSites(array $sites): array
    {
        $counts = [];
        foreach ($sites as $site) {
            $counts[$this->keyOf($site)] = 0;
        }
        if ($sites === []) {
            return $counts;
        }

        $rows = $this->createQueryBuilder('scan')
            ->select('IDENTITY(scan.site) AS siteId', 'COUNT(scan.id) AS scansCount')
            ->where('scan.site IN (:sites)')
            ->andWhere('scan.hiddenAt IS NULL')
            ->groupBy('scan.site')
            ->setParameter('sites', $sites)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(string) $row['siteId']] = (int) $row['scansCount'];
        }

        return $counts;
    }

    /**
     * @param list<MiningSite> $sites
     *
     * @return array<string, MiningSiteScan>
     */
    private function findVisibleBoundOfSites(array $sites, string $aggregate): array
    {
        if ($sites === []) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('scan');
        $bound = $this->createQueryBuilder('bound')
            ->select(sprintf('%s(bound.scannedAt)', $aggregate))
            ->where('bound.site = scan.site')
            ->andWhere('bound.hiddenAt IS NULL');

        $scans = $queryBuilder
            ->where('scan.site IN (:sites)')
            ->andWhere('scan.hiddenAt IS NULL')
            ->andWhere(sprintf('scan.scannedAt = (%s)', $bound->getDQL()))
            ->setParameter('sites', $sites)
            ->getQuery()
            ->getResult();

        $scansBySite = [];
        foreach ($scans as $scan) {
            $scansBySite[$this->keyOf($scan->getSite())] = $scan;
        }

        return $scansBySite;
    }

    private function keyOf(MiningSite $site): string
    {
        return ($site->getId() ?? throw new \LogicException('A persisted site has an id'))->toRfc4122();
    }
}
