<?php

declare(strict_types=1);

namespace App\State\Provider\ShoppingList;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ShoppingList\SharedShoppingListResource;
use App\Repository\SharedShoppingListRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<SharedShoppingListResource>
 */
class SharedShoppingListProvider implements ProviderInterface
{
    public function __construct(
        private readonly SharedShoppingListRepository $repository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SharedShoppingListResource
    {
        $token = $uriVariables['token'] ?? '';

        $sharedList = $this->repository->findByToken($token);

        if ($sharedList === null) {
            throw new NotFoundHttpException('Shopping list not found or expired');
        }

        $data = $sharedList->getData();

        $resource = new SharedShoppingListResource();
        $resource->token = $sharedList->getToken();
        $resource->items = $data['items'] ?? [];
        $resource->notFound = $data['notFound'] ?? [];
        $resource->totals = $data['totals'] ?? [];
        $resource->transportCostPerM3 = $data['transportCostPerM3'] ?? 1200.0;
        $resource->structureId = $data['structureId'] ?? null;
        $resource->structureName = $data['structureName'] ?? null;
        $resource->createdAt = $sharedList->getCreatedAt()->format('c');
        $resource->expiresAt = $sharedList->getExpiresAt()->format('c');

        return $resource;
    }
}
