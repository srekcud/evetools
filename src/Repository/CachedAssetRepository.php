<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\User;
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
     * @param int[] $divisionNumbers Division numbers (1-7)
     * @return CachedAsset[]
     */
    public function findByCorporationAndDivisions(int $corporationId, array $divisionNumbers): array
    {
        $flags = self::divisionNumbersToFlags($divisionNumbers);

        return $this->createQueryBuilder('a')
            ->where('a.corporationId = :corporationId')
            ->andWhere('a.isCorporationAsset = true')
            ->andWhere('a.locationFlag IN (:flags)')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('flags', $flags)
            ->orderBy('a.locationName', 'ASC')
            ->addOrderBy('a.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Filter by division name AND restrict to visible division flags.
     *
     * @param int[] $divisionNumbers Division numbers (1-7)
     * @return CachedAsset[]
     */
    public function findByCorporationDivisionNameAndFlags(int $corporationId, string $divisionName, array $divisionNumbers): array
    {
        $flags = self::divisionNumbersToFlags($divisionNumbers);

        return $this->createQueryBuilder('a')
            ->where('a.corporationId = :corporationId')
            ->andWhere('a.divisionName = :divisionName')
            ->andWhere('a.isCorporationAsset = true')
            ->andWhere('a.locationFlag IN (:flags)')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('divisionName', $divisionName)
            ->setParameter('flags', $flags)
            ->orderBy('a.locationName', 'ASC')
            ->addOrderBy('a.typeName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $divisionNumbers
     * @return string[]
     */
    private static function divisionNumbersToFlags(array $divisionNumbers): array
    {
        return array_map(
            static fn (int $n): string => "CorpSAG{$n}",
            $divisionNumbers,
        );
    }

    /**
     * Get distinct division numbers and names for a corporation from cached assets.
     *
     * @return array<int, string> Division number => division name (e.g. [1 => 'Minerals', 3 => 'Ships'])
     */
    public function findDistinctDivisions(int $corporationId): array
    {
        $results = $this->createQueryBuilder('a')
            ->select('DISTINCT a.locationFlag, a.divisionName')
            ->where('a.corporationId = :corporationId')
            ->andWhere('a.isCorporationAsset = true')
            ->andWhere('a.locationFlag LIKE :sagPrefix')
            ->setParameter('corporationId', $corporationId)
            ->setParameter('sagPrefix', 'CorpSAG%')
            ->getQuery()
            ->getResult();

        $divisions = [];
        foreach ($results as $row) {
            if (preg_match('/^CorpSAG(\d+)$/', $row['locationFlag'], $matches)) {
                $divisionNumber = (int) $matches[1];
                $divisions[$divisionNumber] = $row['divisionName'] ?? "Division {$divisionNumber}";
            }
        }

        ksort($divisions);

        return $divisions;
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

    /**
     * Aggregate personal asset quantities by typeId across all user characters.
     *
     * @return array<int, int> typeId => totalQuantity
     */
    public function getAggregatedQuantitiesByUser(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT a.type_id, SUM(a.quantity) AS total_qty
            FROM cached_assets a
            JOIN characters c ON c.id = a.character_id
            WHERE c.user_id = :userId
              AND a.is_corporation_asset = false
            GROUP BY a.type_id
        SQL;

        $rows = $conn->fetchAllAssociative($sql, ['userId' => $user->getId()]);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['type_id']] = (int) $row['total_qty'];
        }

        return $result;
    }

    /**
     * Aggregate personal asset quantities by typeId with location breakdown.
     *
     * @return array<int, array{total: int, locations: list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}>}>
     */
    public function getQuantitiesByUserWithLocations(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT a.type_id, a.location_id, a.location_name, a.solar_system_name, SUM(a.quantity) AS qty
            FROM cached_assets a
            JOIN characters c ON c.id = a.character_id
            WHERE c.user_id = :userId
              AND a.is_corporation_asset = false
            GROUP BY a.type_id, a.location_id, a.location_name, a.solar_system_name
            ORDER BY a.type_id, qty DESC
        SQL;

        $rows = $conn->fetchAllAssociative($sql, ['userId' => $user->getId()]);

        $result = [];
        foreach ($rows as $row) {
            $typeId = (int) $row['type_id'];
            if (!isset($result[$typeId])) {
                $result[$typeId] = ['total' => 0, 'locations' => []];
            }
            $qty = (int) $row['qty'];
            $result[$typeId]['total'] += $qty;
            $result[$typeId]['locations'][] = [
                'locationId' => (int) $row['location_id'],
                'locationName' => $row['location_name'] ?? 'Unknown',
                'systemName' => $row['solar_system_name'],
                'quantity' => $qty,
            ];
        }

        return $result;
    }
}
