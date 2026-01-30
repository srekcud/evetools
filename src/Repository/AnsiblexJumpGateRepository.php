<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnsiblexJumpGate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnsiblexJumpGate>
 */
class AnsiblexJumpGateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnsiblexJumpGate::class);
    }

    public function save(AnsiblexJumpGate $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AnsiblexJumpGate $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all active Ansiblex gates.
     *
     * @return AnsiblexJumpGate[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('a.sourceSolarSystemName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find gates by alliance.
     *
     * @return AnsiblexJumpGate[]
     */
    public function findByAlliance(int $allianceId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.ownerAllianceId = :allianceId')
            ->andWhere('a.isActive = :active')
            ->setParameter('allianceId', $allianceId)
            ->setParameter('active', true)
            ->orderBy('a.sourceSolarSystemName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get gates connected to a specific system.
     *
     * @return AnsiblexJumpGate[]
     */
    public function findBySourceSystem(int $solarSystemId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.sourceSolarSystemId = :systemId')
            ->andWhere('a.isActive = :active')
            ->setParameter('systemId', $solarSystemId)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the adjacency list for pathfinding (active gates only).
     *
     * @return array<int, int[]> Map of solarSystemId => [connectedSystemIds]
     */
    public function getAdjacencyList(): array
    {
        $gates = $this->createQueryBuilder('a')
            ->select('a.sourceSolarSystemId, a.destinationSolarSystemId')
            ->where('a.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getArrayResult();

        $graph = [];
        foreach ($gates as $gate) {
            $from = $gate['sourceSolarSystemId'];
            $to = $gate['destinationSolarSystemId'];

            if (!isset($graph[$from])) {
                $graph[$from] = [];
            }
            $graph[$from][] = $to;

            // Ansiblex are bidirectional
            if (!isset($graph[$to])) {
                $graph[$to] = [];
            }
            $graph[$to][] = $from;
        }

        return $graph;
    }

    /**
     * Find gate by source and destination.
     */
    public function findByRoute(int $sourceSystemId, int $destinationSystemId): ?AnsiblexJumpGate
    {
        return $this->createQueryBuilder('a')
            ->where('a.sourceSolarSystemId = :source AND a.destinationSolarSystemId = :dest')
            ->orWhere('a.sourceSolarSystemId = :dest AND a.destinationSolarSystemId = :source')
            ->setParameter('source', $sourceSystemId)
            ->setParameter('dest', $destinationSystemId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Deactivate gates not seen since a given date.
     */
    public function deactivateStaleGates(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('a')
            ->update()
            ->set('a.isActive', ':inactive')
            ->where('a.lastSeenAt < :threshold')
            ->andWhere('a.isActive = :active')
            ->setParameter('inactive', false)
            ->setParameter('threshold', $threshold)
            ->setParameter('active', true)
            ->getQuery()
            ->execute();
    }
}
