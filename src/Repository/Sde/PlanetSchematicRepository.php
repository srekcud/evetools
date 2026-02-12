<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\PlanetSchematic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanetSchematic>
 */
class PlanetSchematicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetSchematic::class);
    }

    public function findBySchematicId(int $schematicId): ?PlanetSchematic
    {
        return $this->find($schematicId);
    }
}
