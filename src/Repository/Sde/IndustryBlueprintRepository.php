<?php

declare(strict_types=1);

namespace App\Repository\Sde;

use App\Entity\Sde\IndustryBlueprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryBlueprint>
 */
class IndustryBlueprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryBlueprint::class);
    }
}
