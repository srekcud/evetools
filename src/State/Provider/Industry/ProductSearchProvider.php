<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\ProductSearchListResource;
use App\ApiResource\Industry\ProductSearchResource;
use App\Service\Industry\ManufacturableProduct;
use App\Service\Industry\ManufacturableProductReader;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<ProductSearchListResource>
 */
class ProductSearchProvider implements ProviderInterface
{
    private const int MINIMUM_QUERY_LENGTH = 2;
    private const int RESULT_LIMIT = 20;

    public function __construct(
        private readonly ManufacturableProductReader $manufacturableProductReader,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductSearchListResource
    {
        $request = $this->requestStack->getCurrentRequest();
        $query = $request?->query->get('q', '') ?? '';

        $resource = new ProductSearchListResource();

        if (strlen($query) < self::MINIMUM_QUERY_LENGTH) {
            return $resource;
        }

        $resource->results = array_map(function (ManufacturableProduct $product): ProductSearchResource {
            $item = new ProductSearchResource();
            $item->typeId = $product->typeId;
            $item->typeName = $product->typeName;
            $item->isT2 = $product->isT2;

            return $item;
        }, $this->manufacturableProductReader->search($query, self::RESULT_LIMIT));

        return $resource;
    }
}
