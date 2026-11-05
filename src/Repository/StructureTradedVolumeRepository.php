<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StructureTradedVolume;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StructureTradedVolume>
 */
class StructureTradedVolumeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StructureTradedVolume::class);
    }

    public function purgeOlderThan(int $days): int
    {
        $cutoff = new \DateTimeImmutable("-{$days} days");

        return (int) $this->createQueryBuilder('v')
            ->delete()
            ->where('v.date < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
