<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryStructureConfig>
 */
class IndustryStructureConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryStructureConfig::class);
    }

    /**
     * @return IndustryStructureConfig[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.isDeleted = false')
            ->setParameter('user', $user)
            ->orderBy('s.isDefault', 'DESC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefaultForUser(User $user): ?IndustryStructureConfig
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.isDefault = true')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUserAndLocationId(User $user, int $locationId): ?IndustryStructureConfig
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.locationId = :locationId')
            ->andWhere('s.isDeleted = false')
            ->setParameter('user', $user)
            ->setParameter('locationId', $locationId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function clearDefaultForUser(User $user): void
    {
        $this->createQueryBuilder('s')
            ->update()
            ->set('s.isDefault', 'false')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Find configurations shared by corporation members for specific locations.
     * Returns the most recent configuration for each locationId.
     *
     * @param int $corporationId
     * @param int[] $locationIds
     * @return array<int, IndustryStructureConfig> Indexed by locationId
     */
    public function findSharedByCorporationAndLocations(int $corporationId, array $locationIds): array
    {
        if (empty($locationIds)) {
            return [];
        }

        $configs = $this->createQueryBuilder('s')
            ->where('s.corporationId = :corporationId')
            ->andWhere('s.locationId IN (:locationIds)')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('locationIds', $locationIds)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Group by locationId, keeping only the most recent
        $result = [];
        foreach ($configs as $config) {
            $locId = $config->getLocationId();
            if ($locId !== null && !isset($result[$locId])) {
                $result[$locId] = $config;
            }
        }

        return $result;
    }

    /**
     * Find all corporation structures marked as shared (isCorporationStructure = true).
     * Excludes structures actively configured by the given user (but includes their soft-deleted ones).
     *
     * @return IndustryStructureConfig[] Indexed by locationId
     */
    public function findCorporationSharedStructures(int $corporationId, User $excludeUser): array
    {
        // Get:
        // - Active (non-deleted) structures from OTHER users
        // - Soft-deleted structures from CURRENT user (so they can re-import)
        $configs = $this->createQueryBuilder('s')
            ->where('s.corporationId = :corporationId')
            ->andWhere('s.isCorporationStructure = true')
            ->andWhere('s.locationId IS NOT NULL')
            ->andWhere('((s.user != :excludeUser AND s.isDeleted = false) OR (s.user = :excludeUser AND s.isDeleted = true))')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('excludeUser', $excludeUser)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Group by locationId, keeping only the most recent
        $result = [];
        foreach ($configs as $config) {
            $locId = $config->getLocationId();
            if ($locId !== null && !isset($result[$locId])) {
                $result[$locId] = $config;
            }
        }

        return $result;
    }

    /**
     * Check if a structure is shared by another corporation member.
     */
    public function findSharedByLocationId(int $corporationId, int $locationId, User $excludeUser): ?IndustryStructureConfig
    {
        return $this->createQueryBuilder('s')
            ->where('s.corporationId = :corporationId')
            ->andWhere('s.locationId = :locationId')
            ->andWhere('s.isCorporationStructure = true')
            ->andWhere('s.user != :excludeUser')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('locationId', $locationId)
            ->setParameter('excludeUser', $excludeUser)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
