<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssetSyncChange;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetSyncChange>
 */
class AssetSyncChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetSyncChange::class);
    }

    /**
     * @return list<AssetSyncChange>
     */
    public function findForCharacter(Character $character): array
    {
        return array_values($this->createQueryBuilder('c')
            ->where('c.character = :character')
            ->setParameter('character', $character)
            ->orderBy('c.locationName', 'ASC')
            ->addOrderBy('c.typeName', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * @param list<string> $divisionNames
     *
     * @return list<AssetSyncChange>
     */
    public function findForCorporationDivisions(int $corporationId, array $divisionNames): array
    {
        if ($divisionNames === []) {
            return [];
        }

        return array_values($this->createQueryBuilder('c')
            ->where('c.corporationId = :corporationId')
            ->andWhere('c.divisionName IN (:divisionNames)')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('divisionNames', $divisionNames)
            ->orderBy('c.locationName', 'ASC')
            ->addOrderBy('c.typeName', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function deleteForCharacter(Character $character): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.character = :character')
            ->setParameter('character', $character)
            ->getQuery()
            ->execute();
    }

    public function deleteForCorporation(int $corporationId): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.corporationId = :corporationId')
            ->setParameter('corporationId', $corporationId)
            ->getQuery()
            ->execute();
    }
}
