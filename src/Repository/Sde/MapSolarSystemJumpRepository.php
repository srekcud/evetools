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
}
