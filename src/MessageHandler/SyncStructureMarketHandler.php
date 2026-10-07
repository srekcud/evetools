<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Constant\EveConstants;
use App\Message\SyncStructureMarket;
use App\Repository\CharacterRepository;
use App\Service\StructureMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncStructureMarketHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private StructureMarketService $structureMarketService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncStructureMarket $message): void
    {
        $character = $this->characterRepository->find(Uuid::fromString($message->characterId));

        if ($character === null) {
            $this->logger->warning('Character not found for structure market sync', [
                'characterId' => $message->characterId,
            ]);
            return;
        }

        $token = $character->getEveToken();
        if ($token === null || !$token->hasScope(EveConstants::STRUCTURE_MARKET_SCOPE)) {
            $this->logger->warning('No token with the structure market scope for structure market sync', [
                'characterId' => $message->characterId,
                'structureId' => $message->structureId,
            ]);
            return;
        }

        // Get userId for Mercure notifications
        $userId = $character->getUser()?->getId()?->toRfc4122();

        $result = $this->structureMarketService->syncStructureMarket(
            $message->structureId,
            $message->structureName,
            $token,
            $userId
        );

        if (!$result['success']) {
            // The cause is already logged and pushed to the user by StructureMarketService.
            throw new \RuntimeException(sprintf('Structure market sync failed for structure %d', $message->structureId));
        }
    }
}
