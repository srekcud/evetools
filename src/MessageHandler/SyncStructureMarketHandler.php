<?php

declare(strict_types=1);

namespace App\MessageHandler;

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
        if ($token === null) {
            $this->logger->warning('No token available for structure market sync', [
                'characterId' => $message->characterId,
            ]);
            return;
        }

        $this->structureMarketService->syncStructureMarket(
            $message->structureId,
            $message->structureName,
            $token
        );
    }
}
