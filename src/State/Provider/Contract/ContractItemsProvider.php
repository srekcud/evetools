<?php

declare(strict_types=1);

namespace App\State\Provider\Contract;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Contract\ContractItemResource;
use App\ApiResource\Contract\ContractItemsResource;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ContractItemsResource>
 */
class ContractItemsProvider implements ProviderInterface
{
    private const FORGE_REGION_ID = 10000002;

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ContractItemsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            throw new AccessDeniedHttpException('No token available');
        }

        $contractId = (int) $uriVariables['contractId'];
        $characterId = $mainCharacter->getEveCharacterId();

        $items = $this->esiClient->get(
            "/characters/{$characterId}/contracts/{$contractId}/items/",
            $token
        );

        $typeIds = array_unique(array_column($items, 'type_id'));
        $typeNames = $this->resolveTypeNames($typeIds);

        $result = [];
        foreach ($items as $item) {
            $typeId = $item['type_id'];
            $quantity = $item['quantity'];
            $jitaPrice = $this->getLowestSellPrice($typeId);

            $itemResource = new ContractItemResource();
            $itemResource->typeId = $typeId;
            $itemResource->typeName = $typeNames[$typeId] ?? 'Unknown';
            $itemResource->quantity = $quantity;
            $itemResource->isIncluded = $item['is_included'] ?? true;
            $itemResource->isSingleton = $item['is_singleton'] ?? false;
            $itemResource->jitaPrice = $jitaPrice;
            $itemResource->jitaValue = $jitaPrice !== null ? $jitaPrice * $quantity : null;

            $result[] = $itemResource;
        }

        $resource = new ContractItemsResource();
        $resource->items = $result;

        return $resource;
    }

    private function getLowestSellPrice(int $typeId): ?float
    {
        try {
            $orders = $this->esiClient->get(
                "/markets/" . self::FORGE_REGION_ID . "/orders/?type_id={$typeId}&order_type=sell"
            );

            if (empty($orders)) {
                return null;
            }

            return min(array_column($orders, 'price'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int> $typeIds
     * @return array<int, string>
     */
    private function resolveTypeNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $response = $this->esiClient->post('/universe/names/', $typeIds);
            $names = [];
            foreach ($response as $item) {
                $names[$item['id']] = $item['name'];
            }

            return $names;
        } catch (\Throwable) {
            return [];
        }
    }
}
