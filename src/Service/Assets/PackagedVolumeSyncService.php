<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Constant\EveConstants;
use App\Repository\CachedAssetRepository;
use App\Repository\TypePackagedVolumeRepository;
use App\Service\ESI\EsiClient;
use Psr\Log\LoggerInterface;

readonly class PackagedVolumeSyncService
{
    private const int BATCH_SIZE = 20;
    private const int MAX_CONSECUTIVE_EMPTY_BATCHES = 3;

    public function __construct(
        private EsiClient $esiClient,
        private CachedAssetRepository $cachedAssetRepository,
        private TypePackagedVolumeRepository $packagedVolumes,
        private LoggerInterface $logger,
    ) {
    }

    public function sync(): PackagedVolumeSyncResult
    {
        $typeIds = array_values(array_diff($this->cachedAssetRepository->findDistinctTypeIds(), EveConstants::STATION_CONTAINER_TYPE_IDS));
        $refreshedAt = new \DateTimeImmutable();
        $synced = 0;
        $failed = 0;
        $consecutiveEmptyBatches = 0;

        foreach (array_chunk($typeIds, self::BATCH_SIZE) as $batch) {
            $answers = $this->esiClient->getBatch($this->endpointsOf($batch));
            $batchSynced = 0;

            foreach ($batch as $typeId) {
                $volume = self::packagedVolumeOf($answers[$typeId] ?? null);
                if (null === $volume) {
                    ++$failed;
                    continue;
                }
                $this->packagedVolumes->upsert($typeId, $volume, $refreshedAt);
                ++$batchSynced;
            }

            $synced += $batchSynced;
            $consecutiveEmptyBatches = 0 === $batchSynced ? $consecutiveEmptyBatches + 1 : 0;

            if ($consecutiveEmptyBatches >= self::MAX_CONSECUTIVE_EMPTY_BATCHES) {
                $this->logger->warning('Packaged volume sync abandoned, ESI keeps failing', ['synced' => $synced, 'failed' => $failed]);

                return new PackagedVolumeSyncResult($synced, $failed, true);
            }
        }

        return new PackagedVolumeSyncResult($synced, $failed, false);
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, string>
     */
    private function endpointsOf(array $typeIds): array
    {
        $endpoints = [];
        foreach ($typeIds as $typeId) {
            $endpoints[$typeId] = sprintf('/universe/types/%d/', $typeId);
        }

        return $endpoints;
    }

    /**
     * @param array<mixed>|null $answer
     */
    private static function packagedVolumeOf(?array $answer): ?float
    {
        if (null === $answer) {
            return null;
        }

        $volume = $answer['packaged_volume'] ?? $answer['volume'] ?? null;
        if (!is_int($volume) && !is_float($volume)) {
            return null;
        }

        return $volume >= 0 ? (float) $volume : null;
    }
}
