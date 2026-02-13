<?php

declare(strict_types=1);

namespace App\State\Processor\ShoppingList;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ShoppingList\SyncMarketInput;
use App\ApiResource\ShoppingList\SyncMarketResultResource;
use App\Entity\User;
use App\Service\ESI\MarketService;
use App\Service\StructureMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<SyncMarketInput, SyncMarketResultResource>
 */
class SyncMarketProcessor implements ProcessorInterface
{
    private const DEFAULT_STRUCTURE_ID = 1049588174021;
    private const DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';

    public function __construct(
        private readonly Security $security,
        private readonly MarketService $marketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SyncMarketResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof SyncMarketInput);

        $structureId = $data->structureId ?? self::DEFAULT_STRUCTURE_ID;

        $token = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                break;
            }
        }

        if ($token === null) {
            throw new BadRequestHttpException('No character with valid token');
        }

        $structureName = $this->marketService->getStructureName($structureId, $token);
        if ($structureName === null) {
            $structureName = $structureId === self::DEFAULT_STRUCTURE_ID
                ? self::DEFAULT_STRUCTURE_NAME
                : "Structure {$structureId}";
        }

        $resource = new SyncMarketResultResource();
        $resource->structureId = $structureId;
        $resource->structureName = $structureName;

        try {
            $this->structureMarketService->clearCache($structureId);

            $userId = $user->getId()?->toRfc4122();
            $result = $this->structureMarketService->syncStructureMarket($structureId, $structureName, $token, $userId);

            if (!$result['success']) {
                $resource->success = false;
                $resource->error = $result['error'] ?? 'Sync failed';

                return $resource;
            }

            $resource->success = true;
            $resource->totalOrders = $result['totalOrders'];
            $resource->sellOrders = $result['sellOrders'];
            $resource->typeCount = $result['typeCount'];
        } catch (\Throwable $e) {
            $this->logger->error('Structure market sync failed', [
                'structureId' => $structureId,
                'error' => $e->getMessage(),
            ]);

            $resource->success = false;
            $resource->error = 'Sync failed: ' . $e->getMessage();
        }

        return $resource;
    }
}
