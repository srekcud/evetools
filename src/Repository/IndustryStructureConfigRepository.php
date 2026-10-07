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

    public function findDeletedByUserAndLocationId(User $user, int $locationId): ?IndustryStructureConfig
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.locationId = :locationId')
            ->andWhere('s.isDeleted = true')
            ->setParameter('user', $user)
            ->setParameter('locationId', $locationId)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByLocationId(int $locationId): ?IndustryStructureConfig
    {
        return $this->createQueryBuilder('s')
            ->where('s.locationId = :locationId')
            ->andWhere('s.isDeleted = false')
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
     * Find the corporation structures shared by the OTHER members of the corporation.
     * Rows soft-deleted by their owner stay shared; the given user's own rows (deleted or not) are never listed.
     *
     * @return IndustryStructureConfig[] Indexed by locationId
     */
    public function findCorporationSharedStructures(int $corporationId, User $excludeUser): array
    {
        $configs = $this->createQueryBuilder('s')
            ->where('s.corporationId = :corporationId')
            ->andWhere('s.isCorporationStructure = true')
            ->andWhere('s.locationId IS NOT NULL')
            ->andWhere('s.user != :excludeUser')
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
}
