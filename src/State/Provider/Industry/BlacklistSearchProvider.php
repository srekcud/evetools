<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProductSearchListResource;
use App\ApiResource\Industry\ProductSearchResource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<ProductSearchListResource>
 */
class BlacklistSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductSearchListResource
    {
        $request = $this->requestStack->getCurrentRequest();
        $query = $request?->query->get('q', '') ?? '';

        $resource = new ProductSearchListResource();

        if (strlen($query) < 2) {
            return $resource;
        }

        $conn = $this->entityManager->getConnection();
        $sql = <<<SQL
            SELECT DISTINCT t.type_id, t.type_name
            FROM sde_inv_types t
            INNER JOIN sde_industry_activity_products p ON p.product_type_id = t.type_id AND p.activity_id IN (1, 11)
            WHERE t.published = true
              AND LOWER(t.type_name) LIKE LOWER(:query)
            ORDER BY t.type_name
            LIMIT 20
        SQL;

        $results = $conn->fetchAllAssociative($sql, ['query' => "%{$query}%"]);

        $resource->results = array_map(function (array $row) {
            $item = new ProductSearchResource();
            $item->typeId = (int) $row['type_id'];
            $item->typeName = $row['type_name'];

            return $item;
        }, $results);

        return $resource;
    }
}
