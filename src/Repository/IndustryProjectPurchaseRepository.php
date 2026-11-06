<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryProjectPurchase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryProjectPurchase>
 */
class IndustryProjectPurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryProjectPurchase::class);
    }
}
