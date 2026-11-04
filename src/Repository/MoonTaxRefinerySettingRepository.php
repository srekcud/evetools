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
        $rows = $this->createQueryBuilder('setting')
            ->select('DISTINCT setting.corporationId AS corporationId')
            ->where('setting.openToAlliance = true')
            ->orderBy('setting.corporationId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): int => (int) $row['corporationId'], $rows));
    }

    /**
     * @return list<MoonTaxRefinerySetting>
     */
    public function findOpenOfCorporation(int $corporationId): array
    {
        return $this->findBy(['corporationId' => $corporationId, 'openToAlliance' => true], ['structureId' => 'ASC']);
    }

    /**
     * @return list<MoonTaxRefinerySetting>
     */
    public function findOpen(): array
    {
        return $this->findBy(['openToAlliance' => true], ['corporationId' => 'ASC', 'structureId' => 'ASC']);
    }
}
