<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedStructure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedStructure>
 */
class CachedStructureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedStructure::class);
    }

    public function findByStructureId(int $structureId): ?CachedStructure
    {
        return $this->findOneBy(['structureId' => $structureId]);
    }

    /**
     * @param int[] $structureIds
     * @return array<int, CachedStructure>
     */
    public function findByStructureIds(array $structureIds): array
    {
        if (empty($structureIds)) {
            return [];
        }

        $results = $this->createQueryBuilder('s')
            ->where('s.structureId IN (:ids)')
            ->setParameter('ids', $structureIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($results as $structure) {
            $indexed[$structure->getStructureId()] = $structure;
        }

        return $indexed;
    }

    /**
     * Find all structures owned by a specific corporation.
     *
     * @return CachedStructure[]
     */
    public function findByOwnerCorporationId(int $corporationId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.ownerCorporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
