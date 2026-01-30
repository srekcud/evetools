<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\MapSolarSystemJump;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MapSolarSystemJump>
 */
class MapSolarSystemJumpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MapSolarSystemJump::class);
    }

    /**
     * Get all systems directly connected to a given system.
     *
     * @return int[] Array of connected solar system IDs
     */
    public function findConnectedSystems(int $solarSystemId): array
    {
        $result = $this->createQueryBuilder('j')
            ->select('j.toSolarSystemId')
            ->where('j.fromSolarSystemId = :systemId')
            ->setParameter('systemId', $solarSystemId)
            ->getQuery()
            ->getArrayResult();

        return array_column($result, 'toSolarSystemId');
    }

    /**
     * Get the full graph as an adjacency list for pathfinding.
     *
     * @return array<int, int[]> Map of solarSystemId => [connectedSystemIds]
     */
    public function getAdjacencyList(): array
    {
        $jumps = $this->createQueryBuilder('j')
            ->select('j.fromSolarSystemId, j.toSolarSystemId')
            ->getQuery()
            ->getArrayResult();

        $graph = [];
        foreach ($jumps as $jump) {
            $from = $jump['fromSolarSystemId'];
            $to = $jump['toSolarSystemId'];

            if (!isset($graph[$from])) {
                $graph[$from] = [];
            }
            $graph[$from][] = $to;
        }

        return $graph;
    }

    public function truncate(): void
    {
        $this->createQueryBuilder('j')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
