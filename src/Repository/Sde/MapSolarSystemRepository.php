<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\MapSolarSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MapSolarSystem>
 */
class MapSolarSystemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MapSolarSystem::class);
    }

    public function findBySolarSystemId(int $solarSystemId): ?MapSolarSystem
    {
        return $this->find($solarSystemId);
    }

    /**
     * @return MapSolarSystem[]
     */
    public function findByRegionId(int $regionId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.regionId = :regionId')
            ->setParameter('regionId', $regionId)
            ->orderBy('s.solarSystemName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MapSolarSystem[]
     */
    public function findHighSec(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.security >= :security')
            ->setParameter('security', 0.5)
            ->orderBy('s.solarSystemName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
