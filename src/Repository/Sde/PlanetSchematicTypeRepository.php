<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\PlanetSchematicType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanetSchematicType>
 */
class PlanetSchematicTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanetSchematicType::class);
    }

    /**
     * @return PlanetSchematicType[]
     */
    public function findBySchematicId(int $schematicId): array
    {
        return $this->findBy(['schematic' => $schematicId]);
    }

    /**
     * Preload all schematics as a map: [schematicId => ['inputs' => [...], 'output' => [...]]]
     * @return array<int, array{inputs: array<int, int>, output: array{typeId: int, quantity: int}|null}>
     */
    public function getSchematicMap(): array
    {
        $all = $this->findAll();
        $map = [];

        foreach ($all as $st) {
            $sid = $st->getSchematicId();
            if (!isset($map[$sid])) {
                $map[$sid] = ['inputs' => [], 'output' => null];
            }

            if ($st->isInput()) {
                $map[$sid]['inputs'][$st->getTypeId()] = $st->getQuantity();
            } else {
                $map[$sid]['output'] = [
                    'typeId' => $st->getTypeId(),
                    'quantity' => $st->getQuantity(),
                ];
            }
        }

        return $map;
    }
}
