<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Enum\IndustryActivityType;
use Doctrine\ORM\EntityManagerInterface;

class ManufacturableProductReader
{
    private const int MAXIMUM_TYPE_ID = 2_147_483_647;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<ManufacturableProduct>
     */
    public function search(string $query, int $limit): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            $this->selectManufacturableProducts('LOWER(t.type_name) LIKE LOWER(:query)').' ORDER BY t.type_name LIMIT '.$limit,
            ['query' => "%{$query}%"],
        );

        return array_map($this->toProduct(...), $rows);
    }

    public function findByTypeId(int $typeId): ?ManufacturableProduct
    {
        if ($typeId > self::MAXIMUM_TYPE_ID) {
            return null;
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            $this->selectManufacturableProducts('t.type_id = :typeId'),
            ['typeId' => $typeId],
        );

        if ([] === $rows) {
            return null;
        }

        $products = array_map($this->toProduct(...), $rows);

        return new ManufacturableProduct(
            typeId: $products[0]->typeId,
            typeName: $products[0]->typeName,
            isT2: [] !== array_filter($products, static fn (ManufacturableProduct $product): bool => $product->isT2),
        );
    }

    private function selectManufacturableProducts(string $condition): string
    {
        $manufacturing = IndustryActivityType::Manufacturing->value;
        $reaction = IndustryActivityType::Reaction->value;
        $invention = IndustryActivityType::Invention->value;

        return <<<SQL
            SELECT DISTINCT t.type_id, t.type_name,
                CASE WHEN inv.product_type_id IS NOT NULL THEN true ELSE false END AS is_t2
            FROM sde_inv_types t
            INNER JOIN sde_industry_activity_products p ON p.product_type_id = t.type_id AND p.activity_id IN ({$manufacturing}, {$reaction})
            LEFT JOIN sde_industry_activity_products inv ON inv.product_type_id = p.type_id AND inv.activity_id = {$invention}
            WHERE t.published = true
              AND {$condition}
        SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toProduct(array $row): ManufacturableProduct
    {
        return new ManufacturableProduct(
            typeId: (int) $row['type_id'],
            typeName: $row['type_name'],
            isT2: (bool) $row['is_t2'],
        );
    }
}
