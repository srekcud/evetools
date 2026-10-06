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
}
