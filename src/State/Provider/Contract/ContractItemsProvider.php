<?php

declare(strict_types=1);

namespace App\State\Provider\Contract;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Contract\ContractItemResource;
use App\ApiResource\Contract\ContractItemsResource;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ContractItemsResource>
 */
class ContractItemsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly JitaMarketService $jitaMarketService,
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

        $typeIds = array_values(array_unique(array_column($items, 'type_id')));
        $typeNames = $this->resolveTypeNames($typeIds);
        $jitaPrices = $this->jitaMarketService->getPricesWithFallback($typeIds);

        $result = [];
        foreach ($items as $item) {
            $typeId = $item['type_id'];
            $quantity = $item['quantity'];
            $jitaPrice = $jitaPrices[$typeId] ?? null;

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
