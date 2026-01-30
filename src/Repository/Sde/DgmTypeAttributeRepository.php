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
     * Get a specific attribute value for a type
     */
    public function findAttribute(int $typeId, int $attributeId): ?DgmTypeAttribute
    {
        return $this->findOneBy([
            'typeId' => $typeId,
            'attributeId' => $attributeId,
        ]);
    }
}
