<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TriggerStructureMarketSync;
use App\Repository\CharacterRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\StructureMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Syncs every requested structure market synchronously, each one with the token of a user who requested it,
 * so that the sync tracker reflects the actual results.
 */
#[AsMessageHandler]
final readonly class TriggerStructureMarketSyncHandler
{
    private const string SYNC_TYPE = 'market-structure';

    public function __construct(
        private CharacterRepository $characterRepository,
        private UserRepository $userRepository,
        private StructureMarketService $structureMarketService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function __invoke(TriggerStructureMarketSync $message): void
    {
        $this->syncTracker->start(self::SYNC_TYPE);
        $this->logger->info('Starting structure market sync');

        try {
            $synced = [];
            $skipped = [];
            $failed = [];

            foreach ($this->getStructuresToSync() as $structureId => $structureName) {
                $requester = $this->characterRepository->findStructureMarketRequester(
                    $structureId,
                    $structureId === $this->defaultMarketStructureId,
                );
                $token = $requester?->getEveToken();

                if ($requester === null || $token === null) {
                    // A structure nobody can read is not a sync failure: it must not keep the tracker red.
                    $this->logger->warning('No requester with the structure market scope, structure market sync skipped', [
                        'structureId' => $structureId,
                        'structureName' => $structureName,
                    ]);
                    $skipped[] = $structureId;
                    continue;
                }

                $result = $this->structureMarketService->syncStructureMarket(
                    $structureId,
                    $structureName,
                    $token,
                    $requester->getUser()?->getId()?->toRfc4122(),
                );

                if ($result['success']) {
                    $synced[] = $structureId;
                } else {
                    $failed[] = $structureId;
                }
            }
        } catch (\Throwable $e) {
            $this->syncTracker->fail(self::SYNC_TYPE, $e->getMessage());
            throw $e;
        }

        $summary = $this->summarize($synced, $skipped, $failed);

        if ($failed !== []) {
            $this->syncTracker->fail(self::SYNC_TYPE, $summary);
        } else {
            $this->syncTracker->complete(self::SYNC_TYPE, $summary);
        }
    }

    /**
     * Collect the default structure + all distinct user-preferred structures.
     *
     * @return array<int, string> structureId => structureName
     */
    private function getStructuresToSync(): array
    {
        $structures = [
            $this->defaultMarketStructureId => $this->defaultMarketStructureName,
        ];

        $userStructureIds = $this->userRepository->findDistinctPreferredMarketStructureIds();

        foreach ($userStructureIds as $structureId) {
            if (!isset($structures[$structureId])) {
                $structures[$structureId] = "Structure {$structureId}";
            }
        }

        return $structures;
    }

    /**
     * @param list<int> $synced
     * @param list<int> $skipped
     * @param list<int> $failed
     */
    private function summarize(array $synced, array $skipped, array $failed): string
    {
        $parts = [count($synced) . ' synced'];

        if ($skipped !== []) {
            $parts[] = 'skipped (no requester with scope): ' . implode(', ', $skipped);
        }

        if ($failed !== []) {
            $parts[] = 'failed: ' . implode(', ', $failed);
        }

        return implode('; ', $parts);
    }
}
