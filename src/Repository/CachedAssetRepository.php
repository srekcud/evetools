<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedAsset;
use App\Entity\Character;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CachedAsset>
 */
class CachedAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CachedAsset::class);
    }

    public function save(CachedAsset $asset, bool $flush = false): void
    {
        $this->getEntityManager()->persist($asset);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CachedAsset $asset, bool $flush = false): void
    {
        $this->getEntityManager()->remove($asset);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return CachedAsset[]
     */
    public function findByCharacter(Character $character): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.character = :character')
            ->andWhere('a.isCorporationAsset = false')
            ->setParameter('character', $character)
            ->orderBy('a.locationName', 'ASC')
            ->addOrderBy('a.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CachedAsset[]
     */
    public function findByCharacterAndLocation(Character $character, int $locationId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.character = :character')
            ->andWhere('a.locationId = :locationId')
            ->andWhere('a.isCorporationAsset = false')
            ->setParameter('character', $character)
            ->setParameter('locationId', $locationId)
            ->orderBy('a.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CachedAsset[]
     */
    public function findByCorporationId(int $corporationId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.corporationId = :corporationId')
            ->andWhere('a.isCorporationAsset = true')
            ->setParameter('corporationId', $corporationId)
            ->orderBy('a.locationName', 'ASC')
            ->addOrderBy('a.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CachedAsset[]
     */
    public function findByCorporationAndDivision(int $corporationId, string $divisionName): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.corporationId = :corporationId')
            ->andWhere('a.divisionName = :divisionName')
            ->andWhere('a.isCorporationAsset = true')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('divisionName', $divisionName)
            ->orderBy('a.locationName', 'ASC')
            ->addOrderBy('a.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, string>
     */
    public function findDistinctLocationsForCharacter(Character $character): array
    {
        $results = $this->createQueryBuilder('a')
            ->select('a.locationId, a.locationName')
            ->where('a.character = :character')
            ->andWhere('a.isCorporationAsset = false')
            ->setParameter('character', $character)
            ->distinct()
            ->orderBy('a.locationName', 'ASC')
            ->getQuery()
            ->getResult();

        $locations = [];
        foreach ($results as $row) {
            $locations[(int) $row['locationId']] = $row['locationName'];
        }

        return $locations;
    }

    public function deleteByCharacter(Character $character): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.character = :character')
            ->andWhere('a.isCorporationAsset = false')
            ->setParameter('character', $character)
            ->getQuery()
            ->execute();
    }

    public function deleteByCorporationId(int $corporationId): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.corporationId = :corporationId')
            ->andWhere('a.isCorporationAsset = true')
            ->setParameter('corporationId', $corporationId)
            ->getQuery()
            ->execute();
    }
}
