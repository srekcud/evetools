<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporationMiningExtraction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporationMiningExtraction>
 */
class CorporationMiningExtractionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporationMiningExtraction::class);
    }
}
