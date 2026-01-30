<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\StaStation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaStation>
 */
class StaStationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaStation::class);
    }

    public function findByStationId(int $stationId): ?StaStation
    {
        return $this->find($stationId);
    }

    /**
     * @param int[] $stationIds
     * @return array<int, StaStation>
     */
    public function findByStationIds(array $stationIds): array
    {
        $stations = $this->createQueryBuilder('s')
            ->where('s.stationId IN (:stationIds)')
            ->setParameter('stationIds', $stationIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($stations as $station) {
            $indexed[$station->getStationId()] = $station;
        }

        return $indexed;
    }

    /**
     * @return StaStation[]
     */
    public function findBySolarSystemId(int $solarSystemId): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.solarSystem', 'ss')
            ->where('ss.solarSystemId = :solarSystemId')
            ->setParameter('solarSystemId', $solarSystemId)
            ->orderBy('s.stationName', 'ASC')
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
