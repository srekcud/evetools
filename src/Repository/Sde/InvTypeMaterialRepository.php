<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\InvTypeMaterial;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvTypeMaterial>
 */
class InvTypeMaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvTypeMaterial::class);
    }

    /**
     * Get all materials obtained when reprocessing a type.
     *
     * @return InvTypeMaterial[]
     */
    public function findByTypeId(int $typeId): array
    {
        return $this->findBy(['typeId' => $typeId]);
    }

    /**
     * Get materials for multiple types in batch.
     *
     * @param int[] $typeIds
     * @return array<int, InvTypeMaterial[]> typeId => materials
     */
    public function findByTypeIds(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->where('m.typeId IN (:typeIds)')
            ->setParameter('typeIds', $typeIds);

        $results = [];
        foreach ($qb->getQuery()->getResult() as $material) {
            $typeId = $material->getTypeId();
            if (!isset($results[$typeId])) {
                $results[$typeId] = [];
            }
            $results[$typeId][] = $material;
        }

        return $results;
    }
}
