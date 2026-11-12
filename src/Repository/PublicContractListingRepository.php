<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicContractListing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicContractListing>
 */
class PublicContractListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicContractListing::class);
    }

    /**
     * @return list<PublicContractListing>
     */
    public function findUnconcludedInRegion(int $regionId): array
    {
        return array_values($this->createQueryBuilder('l')
            ->where('l.regionId = :regionId')
            ->andWhere('l.missedPolls < :confirmationPolls')
            ->setParameter('regionId', $regionId)
            ->setParameter('confirmationPolls', PublicContractListing::CONFIRMATION_POLLS)
            ->getQuery()
            ->getResult());
    }

    /**
     * @return list<int>
     */
    public function findUnconcludedContractIdsInRegion(int $regionId): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.contractId')
            ->where('l.regionId = :regionId')
            ->andWhere('l.missedPolls < :confirmationPolls')
            ->setParameter('regionId', $regionId)
            ->setParameter('confirmationPolls', PublicContractListing::CONFIRMATION_POLLS)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $contractId): int => (int) $contractId, $rows));
    }

    /**
     * @param list<int> $contractIds
     * @return list<PublicContractListing>
     */
    public function findByContractIds(array $contractIds): array
    {
        if ([] === $contractIds) {
            return [];
        }

        return array_values($this->createQueryBuilder('l')
            ->where('l.contractId IN (:contractIds)')
            ->setParameter('contractIds', $contractIds)
            ->getQuery()
            ->getResult());
    }

    /**
     * @return list<PublicContractListing>
     */
    public function findInRegionLastSeenSince(int $regionId, \DateTimeImmutable $since): array
    {
        return array_values($this->createQueryBuilder('l')
            ->where('l.regionId = :regionId')
            ->andWhere('l.lastSeenAt >= :since')
            ->setParameter('regionId', $regionId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult());
    }

    public function purgeLastSeenBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.lastSeenAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
