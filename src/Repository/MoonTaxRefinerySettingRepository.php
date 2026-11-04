<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MoonTaxRefinerySetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoonTaxRefinerySetting>
 */
class MoonTaxRefinerySettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoonTaxRefinerySetting::class);
    }

    /**
     * @return list<int>
     */
    public function findOpenCorporationIds(): array
    {
        return [];
    }
}
