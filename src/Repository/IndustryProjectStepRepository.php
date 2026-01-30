<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectStep>
 */
class IndustryProjectStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectStep::class);
    }
}
