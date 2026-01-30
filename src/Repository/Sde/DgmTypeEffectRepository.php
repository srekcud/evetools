<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\DgmTypeEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DgmTypeEffect>
 */
class DgmTypeEffectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DgmTypeEffect::class);
    }

    /**
     * @return DgmTypeEffect[]
     */
    public function findByTypeId(int $typeId): array
    {
        return $this->findBy(['typeId' => $typeId]);
    }
}
