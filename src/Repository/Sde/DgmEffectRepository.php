<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\DgmEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DgmEffect>
 */
class DgmEffectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DgmEffect::class);
    }

    public function findByName(string $name): ?DgmEffect
    {
        return $this->findOneBy(['effectName' => $name]);
    }
}
