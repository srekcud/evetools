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

    /**
     * @return MapSolarSystem[]
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function searchByName(string $query, int $limit = 10): array
    {
        $lowerQuery = mb_strtolower($query);

        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            SELECT s.solar_system_id, s.solar_system_name, s.security, s.region_id,
                   r.region_name
            FROM sde_map_solar_systems s
            JOIN sde_map_constellations c ON s.constellation_id = c.constellation_id
            JOIN sde_map_regions r ON c.region_id = r.region_id
            WHERE LOWER(s.solar_system_name) LIKE :contains
            ORDER BY
                CASE
                    WHEN LOWER(s.solar_system_name) = :exact THEN 0
                    WHEN LOWER(s.solar_system_name) LIKE :starts THEN 1
                    ELSE 2
                END,
                s.solar_system_name ASC
            LIMIT :lim
        SQL;

        return $conn->fetchAllAssociative($sql, [
            'exact' => $lowerQuery,
            'starts' => $lowerQuery . '%',
            'contains' => '%' . $lowerQuery . '%',
            'lim' => $limit,
        ], [
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
