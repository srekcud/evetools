<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class ManufacturableProductReader
{
    private const int MAXIMUM_TYPE_ID = 2_147_483_647;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryActivityProductRepository $activityProductRepository,
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

        return $this->toProducts($rows);
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

        $products = $this->toProducts($rows);

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

        return <<<SQL
            SELECT DISTINCT t.type_id, t.type_name
            FROM sde_inv_types t
            INNER JOIN sde_industry_activity_products p ON p.product_type_id = t.type_id AND p.activity_id IN ({$manufacturing}, {$reaction})
            WHERE t.published = true
              AND {$condition}
        SQL;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ManufacturableProduct>
     */
    private function toProducts(array $rows): array
    {
        $t2ProductTypeIds = $this->activityProductRepository->findT2ProductTypeIds(
            array_map(static fn (array $row): int => (int) $row['type_id'], $rows),
        );

        return array_map(
            static fn (array $row): ManufacturableProduct => new ManufacturableProduct(
                typeId: (int) $row['type_id'],
                typeName: $row['type_name'],
                isT2: isset($t2ProductTypeIds[(int) $row['type_id']]),
            ),
            $rows,
        );
    }
}
