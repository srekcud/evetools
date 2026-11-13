<?php

declare(strict_types=1);

namespace App\Repository;

use App\Constant\EveConstants;
use App\Dto\Assets\AssetScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

class AssetBrowseRepository
{
    private const int MAX_CONTAINER_DEPTH = 5;

    private const string SEARCH_ESCAPE = '!';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{locationId: int, locationType: string, locationName: string, solarSystemName: ?string, itemCount: int, shipCount: int, divisions: list<array{name: string, count: int}>, cachedAt: string}>
     */
    public function summarizeLocations(AssetScope $scope): array
    {
        $shipCategory = EveConstants::SHIP_CATEGORY_ID;
        $locationRows = $this->connection->fetchAllAssociative(
            $this->treeCte($scope) . <<<SQL

            SELECT root_location_id AS location_id,
                   MAX(root_location_type) AS location_type,
                   MAX(root_location_name) AS location_name,
                   MAX(root_system_name) AS solar_system_name,
                   SUM(quantity) AS item_count,
                   SUM(CASE WHEN is_root AND is_singleton IS TRUE AND category_id = {$shipCategory} THEN quantity ELSE 0 END) AS ship_count,
                   MAX(cached_at) AS cached_at
            FROM tree
            GROUP BY root_location_id
            ORDER BY item_count DESC, location_name ASC, root_location_id ASC
            SQL,
            ...$this->bindings($scope),
        );

        $divisionRows = $this->connection->fetchAllAssociative(
            $this->treeCte($scope) . <<<'SQL'

            SELECT root_location_id AS location_id, root_division_name AS name, SUM(quantity) AS item_count
            FROM tree
            WHERE root_division_name IS NOT NULL
            GROUP BY root_location_id, root_division_name
            ORDER BY item_count DESC, root_division_name ASC
            SQL,
            ...$this->bindings($scope),
        );

        $divisionsByLocation = [];
        foreach ($divisionRows as $row) {
            $divisionsByLocation[(int) $row['location_id']][] = ['name' => (string) $row['name'], 'count' => (int) $row['item_count']];
        }

        return array_map(
            static fn (array $row): array => [
                'locationId' => (int) $row['location_id'],
                'locationType' => (string) $row['location_type'],
                'locationName' => (string) $row['location_name'],
                'solarSystemName' => $row['solar_system_name'],
                'itemCount' => (int) $row['item_count'],
                'shipCount' => (int) $row['ship_count'],
                'divisions' => $divisionsByLocation[(int) $row['location_id']] ?? [],
                'cachedAt' => self::formatTimestamp((string) $row['cached_at']),
            ],
            $locationRows,
        );
    }

    /**
     * @return array{itemCount: int, typeCount: int, systemCount: int, locationCount: int, lastSyncedAt: ?string}
     */
    public function totals(AssetScope $scope): array
    {
        $row = $this->connection->fetchAssociative(
            $this->treeCte($scope) . <<<'SQL'

            SELECT COALESCE(SUM(quantity), 0) AS item_count,
                   COUNT(DISTINCT type_id) AS type_count,
                   COUNT(DISTINCT root_system_name) AS system_count,
                   COUNT(DISTINCT root_location_id) AS location_count,
                   MAX(cached_at) AS last_synced_at
            FROM tree
            SQL,
            ...$this->bindings($scope),
        );
        \assert(\is_array($row));

        return [
            'itemCount' => (int) $row['item_count'],
            'typeCount' => (int) $row['type_count'],
            'systemCount' => (int) $row['system_count'],
            'locationCount' => (int) $row['location_count'],
            'lastSyncedAt' => $row['last_synced_at'] === null ? null : self::formatTimestamp((string) $row['last_synced_at']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rootItemsOf(AssetScope $scope, int $locationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->itemSelect($scope) . ' WHERE ' . $this->scopeSql($scope, 'a') . ' AND ' . $this->rootSql($scope, 'a')
                . ' AND a.location_id = :locationId ORDER BY a.type_name ASC, a.item_id ASC',
            ...$this->bindings($scope, ['locationId' => $locationId]),
        );

        return array_map(self::toItemRow(...), $rows);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function contentsOf(AssetScope $scope, int $containerItemId): ?array
    {
        if (!$this->isReachable($scope, $containerItemId)) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            $this->itemSelect($scope) . ' WHERE ' . $this->scopeSql($scope, 'a')
                . " AND a.location_type = 'item' AND a.location_id = :containerItemId ORDER BY a.type_name ASC, a.item_id ASC",
            ...$this->bindings($scope, ['containerItemId' => $containerItemId]),
        );

        return array_map(self::toItemRow(...), $rows);
    }

    /**
     * @return array{locationIds: list<int>, hits: list<array<string, mixed>>, truncated: bool}
     */
    public function search(AssetScope $scope, string $term, int $limit): array
    {
        $escape = self::SEARCH_ESCAPE;
        $escapedPattern = '%' . preg_replace('/([%_' . preg_quote($escape, '/') . '])/', $escape . '$1', $term) . '%';

        $locationIds = $this->connection->fetchFirstColumn(
            $this->treeCte($scope) . <<<SQL

            SELECT root_location_id
            FROM tree
            WHERE is_root AND (root_location_name ILIKE :pattern ESCAPE '{$escape}' OR root_system_name ILIKE :pattern ESCAPE '{$escape}')
            GROUP BY root_location_id
            ORDER BY MAX(root_location_name) ASC, root_location_id ASC
            SQL,
            ...$this->bindings($scope, ['pattern' => $escapedPattern]),
        );

        $hitRows = $this->connection->fetchAllAssociative(
            $this->treeCte($scope) . <<<SQL

            SELECT tree.root_location_id, to_json(tree.path)::text AS container_path, found.*
            FROM tree
            JOIN ({$this->itemSelect($scope)} WHERE {$this->scopeSql($scope, 'a')}) found ON found.id = tree.id
            WHERE found.type_name ILIKE :pattern ESCAPE '{$escape}' OR found.item_name ILIKE :pattern ESCAPE '{$escape}'
            ORDER BY found.type_name ASC, found.item_id ASC
            LIMIT :hitLimit
            SQL,
            ...$this->bindings($scope, ['pattern' => $escapedPattern, 'hitLimit' => $limit + 1]),
        );

        return [
            'locationIds' => array_map(intval(...), $locationIds),
            'hits' => array_map(
                static fn (array $row): array => [
                    ...self::toItemRow($row),
                    'rootLocationId' => (int) $row['root_location_id'],
                    'containerPath' => array_map(intval(...), json_decode((string) $row['container_path'], true, flags: \JSON_THROW_ON_ERROR)),
                ],
                \array_slice($hitRows, 0, $limit),
            ),
            'truncated' => \count($hitRows) > $limit,
        ];
    }

    private function isReachable(AssetScope $scope, int $containerItemId): bool
    {
        $maxDepth = self::MAX_CONTAINER_DEPTH;
        $scopeOfStart = $this->scopeSql($scope, 'a');
        $scopeOfParent = $this->scopeSql($scope, 'p');
        $rootOfStart = $this->rootSql($scope, 'a');
        $rootOfParent = $this->rootSql($scope, 'p');

        $reachableRoot = $this->connection->fetchOne(
            <<<SQL
            WITH RECURSIVE ancestry AS (
                SELECT a.item_id, a.location_id, a.location_type, 0 AS depth, ({$rootOfStart}) AS is_root
                FROM cached_assets a
                WHERE {$scopeOfStart} AND a.item_id = :containerItemId
                UNION ALL
                SELECT p.item_id, p.location_id, p.location_type, ancestry.depth + 1, ({$rootOfParent}) AS is_root
                FROM cached_assets p
                JOIN ancestry ON ancestry.location_type = 'item' AND p.item_id = ancestry.location_id AND {$scopeOfParent}
                WHERE ancestry.depth < {$maxDepth} AND NOT ancestry.is_root
            )
            SELECT 1 FROM ancestry WHERE is_root AND depth < {$maxDepth} LIMIT 1
            SQL,
            ...$this->bindings($scope, ['containerItemId' => $containerItemId]),
        );

        return $reachableRoot !== false;
    }

    private function treeCte(AssetScope $scope): string
    {
        $maxDepth = self::MAX_CONTAINER_DEPTH;
        $scopeOfRoot = $this->scopeSql($scope, 'a');
        $rootCondition = $this->rootSql($scope, 'a');
        $scopeOfChild = $this->scopeSql($scope, 'c');

        return <<<SQL
            WITH RECURSIVE tree AS (
                SELECT a.id, a.item_id, a.type_id, a.quantity, a.cached_at, 0 AS depth, true AS is_root,
                       a.location_id AS root_location_id, a.location_type AS root_location_type, a.location_name AS root_location_name,
                       a.solar_system_name AS root_system_name, a.division_name AS root_division_name,
                       g.category_id AS category_id, a.is_singleton AS is_singleton,
                       ARRAY[]::bigint[] AS path
                FROM cached_assets a
                LEFT JOIN sde_inv_types t ON t.type_id = a.type_id
                LEFT JOIN sde_inv_groups g ON g.group_id = t.group_id
                WHERE {$scopeOfRoot} AND {$rootCondition}
                UNION ALL
                SELECT c.id, c.item_id, c.type_id, c.quantity, c.cached_at, tree.depth + 1, false,
                       tree.root_location_id, tree.root_location_type, tree.root_location_name,
                       tree.root_system_name, tree.root_division_name,
                       NULL::integer, NULL::boolean,
                       tree.path || tree.item_id
                FROM cached_assets c
                JOIN tree ON c.location_type = 'item' AND c.location_id = tree.item_id AND {$scopeOfChild}
                WHERE tree.depth < {$maxDepth}
            )
            SQL;
    }

    private function itemSelect(AssetScope $scope): string
    {
        $scopeOfChild = $this->scopeSql($scope, 'c');

        return <<<SQL
            SELECT a.id, a.item_id, a.type_id, a.type_name, g.category_id, g.group_id, a.quantity,
                   a.location_id, a.location_name, a.location_type, a.location_flag,
                   a.solar_system_id, a.solar_system_name, a.item_name, a.division_name, a.is_singleton, a.cached_at,
                   (SELECT COUNT(*) FROM cached_assets c WHERE c.location_type = 'item' AND c.location_id = a.item_id AND {$scopeOfChild}) AS child_count
            FROM cached_assets a
            LEFT JOIN sde_inv_types t ON t.type_id = a.type_id
            LEFT JOIN sde_inv_groups g ON g.group_id = t.group_id
            SQL;
    }

    private function scopeSql(AssetScope $scope, string $alias): string
    {
        return $scope->isCorporation()
            ? "{$alias}.corporation_id = :corporationId AND {$alias}.is_corporation_asset = true"
            : "{$alias}.character_id = :characterId AND {$alias}.is_corporation_asset = false";
    }

    private function rootSql(AssetScope $scope, string $alias): string
    {
        if ($scope->isCorporation()) {
            return "{$alias}.location_flag IN (:rootFlags)"
                . ($scope->divisionName === null ? '' : " AND {$alias}.division_name = :divisionName");
        }

        return "({$alias}.location_type <> 'item' OR NOT EXISTS (SELECT 1 FROM cached_assets parent WHERE parent.item_id = {$alias}.location_id AND "
            . $this->scopeSql($scope, 'parent') . '))';
    }

    /**
     * @param array<string, int|string> $extra
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function bindings(AssetScope $scope, array $extra = []): array
    {
        $values = $extra;
        $types = [];

        if ($scope->isCorporation()) {
            $values['corporationId'] = $scope->corporationId;
            $values['rootFlags'] = $scope->rootFlags;
            $types['rootFlags'] = ArrayParameterType::STRING;
            if ($scope->divisionName !== null) {
                $values['divisionName'] = $scope->divisionName;
            }
        } else {
            $values['characterId'] = $scope->characterId;
        }
        foreach (['locationId', 'containerItemId', 'hitLimit'] as $integerName) {
            if (isset($values[$integerName])) {
                $types[$integerName] = ParameterType::INTEGER;
            }
        }

        return [$values, $types];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function toItemRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'itemId' => (int) $row['item_id'],
            'typeId' => (int) $row['type_id'],
            'typeName' => $row['type_name'],
            'categoryId' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'groupId' => $row['group_id'] === null ? null : (int) $row['group_id'],
            'quantity' => (int) $row['quantity'],
            'locationId' => (int) $row['location_id'],
            'locationName' => (string) $row['location_name'],
            'locationType' => (string) $row['location_type'],
            'locationFlag' => $row['location_flag'],
            'solarSystemId' => $row['solar_system_id'] === null ? null : (int) $row['solar_system_id'],
            'solarSystemName' => $row['solar_system_name'],
            'itemName' => $row['item_name'],
            'divisionName' => $row['division_name'],
            'isSingleton' => $row['is_singleton'] === null ? null : (bool) $row['is_singleton'],
            'cachedAt' => self::formatTimestamp((string) $row['cached_at']),
            'childCount' => (int) $row['child_count'],
        ];
    }

    private static function formatTimestamp(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp))->format('c');
    }
}
