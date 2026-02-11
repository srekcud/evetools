<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryStepPurchase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryStepPurchase>
 */
class IndustryStepPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryStepPurchase::class);
    }
}
