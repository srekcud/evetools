<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Constant\EveConstants;
use App\Message\SyncRegionalMarketVolumes;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\JitaMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncRegionalMarketVolumesHandler
{
    private const string SYNC_TYPE = 'market-volumes';

    public function __construct(
        private IndustryActivityProductRepository $productRepository,
        private UserRepository $userRepository,
        private CachedStructureRepository $cachedStructureRepository,
        private MapSolarSystemRepository $solarSystemRepository,
        private JitaMarketService $jitaMarketService,
        private SyncTracker $syncTracker,
        private LoggerInterface $logger,
        private int $defaultMarketStructureId,
    ) {
    }

    public function __invoke(SyncRegionalMarketVolumes $message): void
    {
        $this->syncTracker->start(self::SYNC_TYPE);
        $this->logger->info('Starting regional market volumes sync');

        try {
            $structureIds = array_values(array_unique([
                $this->defaultMarketStructureId,
                ...$this->userRepository->findDistinctMarketStructureIds(),
            ]));

            $regionIds = [EveConstants::THE_FORGE_REGION_ID => EveConstants::THE_FORGE_REGION_ID];
            $skippedStructureIds = [];
            foreach ($structureIds as $structureId) {
                $regionId = $this->resolveRegionId($structureId);
                if ($regionId === null) {
                    $skippedStructureIds[] = $structureId;
                    continue;
                }
                $regionIds[$regionId] = $regionId;
            }

            $typeIds = $this->manufacturableProductTypeIds();

            $synced = 0;
            $failed = 0;
            $abandonedRegionIds = [];
            foreach ($regionIds as $regionId) {
                $result = $this->jitaMarketService->syncRegionalDailyVolumes($regionId, $typeIds);
                $synced += $result['synced'];
                $failed += $result['failed'];
                if ($result['aborted']) {
                    $abandonedRegionIds[] = $regionId;
                }
            }
        } catch (\Throwable $e) {
            $this->syncTracker->fail(self::SYNC_TYPE, $e->getMessage());
            throw $e;
        }

        $summary = $this->summarize(count($regionIds), $synced, $failed, $skippedStructureIds, $abandonedRegionIds);

        if ($failed > 0 || $abandonedRegionIds !== []) {
            $this->syncTracker->fail(self::SYNC_TYPE, $summary);
        } else {
            $this->syncTracker->complete(self::SYNC_TYPE, $summary);
        }
    }

    /**
     * @return list<int>
     */
    private function manufacturableProductTypeIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $product): int => $product['productTypeId'],
            $this->productRepository->findAllManufacturableProducts(),
        )));
    }

    private function resolveRegionId(int $structureId): ?int
    {
        $solarSystemId = $this->cachedStructureRepository->findByStructureId($structureId)?->getSolarSystemId();
        if ($solarSystemId === null) {
            return null;
        }

        return $this->solarSystemRepository->find($solarSystemId)?->getRegionId();
    }

    /**
     * @param list<int> $skippedStructureIds
     * @param list<int> $abandonedRegionIds
     */
    private function summarize(int $regionCount, int $synced, int $failed, array $skippedStructureIds, array $abandonedRegionIds): string
    {
        $parts = [sprintf('%d regions, %d types synced', $regionCount, $synced)];

        if ($skippedStructureIds !== []) {
            $parts[] = 'skipped (region unknown): ' . implode(', ', $skippedStructureIds);
        }

        if ($failed > 0) {
            $parts[] = sprintf('%d types failed', $failed);
        }

        if ($abandonedRegionIds !== []) {
            $parts[] = 'abandoned: ' . implode(', ', $abandonedRegionIds);
        }

        return implode('; ', $parts);
    }
}
