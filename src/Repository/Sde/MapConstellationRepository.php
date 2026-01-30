<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\MapConstellation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MapConstellation>
 */
class MapConstellationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MapConstellation::class);
    }

    public function findByConstellationId(int $constellationId): ?MapConstellation
    {
        return $this->find($constellationId);
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
