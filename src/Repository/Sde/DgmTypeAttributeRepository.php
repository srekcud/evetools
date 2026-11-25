<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\DgmTypeAttribute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DgmTypeAttribute>
 */
class DgmTypeAttributeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DgmTypeAttribute::class);
    }

    /**
     * Get all attributes for a type
     * @return DgmTypeAttribute[]
     */
    public function findByTypeId(int $typeId): array
    {
        return $this->findBy(['typeId' => $typeId]);
    }

    /**
     * @param list<int> $typeIds
     * @param list<int> $attributeIds
     * @return array<int, array<int, float|int|null>>
     */
    public function findValuesByTypeIds(array $typeIds, array $attributeIds): array
    {
        return [];
    }
}
