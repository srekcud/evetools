<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MarketPriceHistory;
use App\Repository\MarketPriceHistoryRepository;
use App\Service\ESI\EsiClient;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class MarketHistoryService
{
    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly MarketPriceHistoryRepository $historyRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch ESI market history for a type/region and persist new entries.
     */
    public function syncHistory(int $typeId, int $regionId = MarketPriceHistory::DEFAULT_REGION_ID): void
    {
        $this->logger->debug('Syncing market history', ['typeId' => $typeId, 'regionId' => $regionId]);

        $endpoint = sprintf('/markets/%d/history/?type_id=%d', $regionId, $typeId);

        /** @var list<array{date: string, order_count: int, volume: int, lowest: float, highest: float, average: float}> $history */
        $history = $this->esiClient->get($endpoint);

        if (empty($history)) {
            return;
        }

        $latestDate = $this->historyRepository->getLatestDate($typeId, $regionId);

        $sql = <<<SQL
            INSERT INTO market_price_history (id, type_id, region_id, date, average, highest, lowest, order_count, volume, created_at)
            VALUES (gen_random_uuid(), :typeId, :regionId, :date, :average, :highest, :lowest, :orderCount, :volume, NOW())
            ON CONFLICT (type_id, region_id, date) DO NOTHING
        SQL;

        $stmt = $this->connection->prepare($sql);
        $inserted = 0;

        foreach ($history as $entry) {
            $entryDate = new \DateTime($entry['date']);

            // Skip entries we already have
            if ($latestDate !== null && $entryDate <= $latestDate) {
                continue;
            }

            $stmt->bindValue('typeId', $typeId);
            $stmt->bindValue('regionId', $regionId);
            $stmt->bindValue('date', $entry['date']);
            $stmt->bindValue('average', $entry['average']);
            $stmt->bindValue('highest', $entry['highest']);
            $stmt->bindValue('lowest', $entry['lowest']);
            $stmt->bindValue('orderCount', $entry['order_count']);
            $stmt->bindValue('volume', $entry['volume']);
            $stmt->executeStatement();
            $inserted++;
        }

        if ($inserted > 0) {
            $this->logger->info('Inserted market history entries', [
                'typeId' => $typeId,
                'regionId' => $regionId,
                'inserted' => $inserted,
            ]);
        }
    }

    /**
     * Get history entries for a type, syncing if stale.
     *
     * @return MarketPriceHistory[]
     */
    public function getHistory(int $typeId, int $days = 30, int $regionId = MarketPriceHistory::DEFAULT_REGION_ID): array
    {
        $latestDate = $this->historyRepository->getLatestDate($typeId, $regionId);
        $yesterday = new \DateTimeImmutable('-1 day');

        // If no data or latest entry is older than 24h, trigger a sync
        if ($latestDate === null || $latestDate < $yesterday) {
            try {
                $this->syncHistory($typeId, $regionId);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync market history', [
                    'typeId' => $typeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->historyRepository->findHistory($typeId, $days, $regionId);
    }

    /**
     * Calculate the price change percentage over the last 30 days.
     * Compares the average price ~30 days ago to the most recent average.
     */
    public function get30dPriceChange(int $typeId, int $regionId = MarketPriceHistory::DEFAULT_REGION_ID): ?float
    {
        $now = new \DateTimeImmutable();
        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');

        $currentPrice = $this->historyRepository->getAveragePriceOnDate($typeId, $now, $regionId);
        $oldPrice = $this->historyRepository->getAveragePriceOnDate($typeId, $thirtyDaysAgo, $regionId);

        if ($currentPrice === null || $oldPrice === null || $oldPrice == 0.0) {
            return null;
        }

        return round(($currentPrice - $oldPrice) / $oldPrice * 100, 2);
    }
}
