<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

class StructureTradedVolumeService
{
    private const string BASELINE_KEY_PREFIX = 'structure_traded_volume_baseline_';
    private const int BASELINE_LIFETIME_SECONDS = 10_800;
    private const int UPSERT_BATCH_SIZE = 1000;
    private const int WINDOW_DAYS = 30;
    private const int MIN_COVERED_DAYS = 7;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'structure_market.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $orders
     */
    public function recordFromSync(int $structureId, iterable $orders, \DateTimeImmutable $syncedAt): void
    {
        $current = $this->orderStates($orders);

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('SELECT pg_advisory_xact_lock(?)', [$structureId]);

            $baselineItem = $this->cache->getItem(self::BASELINE_KEY_PREFIX . $structureId);
            $previous = $baselineItem->isHit() ? $this->readableBaseline($baselineItem->get(), $structureId) : null;

            if ($previous !== null) {
                $currentTypeIds = array_values(array_unique(array_column($current, 'typeId')));
                $tradedVolumeByType = array_replace(
                    array_fill_keys($currentTypeIds, 0),
                    (new OrderVolumeDecreases())->between($previous, $current),
                );
                $this->accumulate($structureId, $tradedVolumeByType, $syncedAt);
            }

            $baselineItem->set($current);
            $baselineItem->expiresAfter(self::BASELINE_LIFETIME_SECONDS);
            $this->cache->save($baselineItem);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, float|null>
     */
    public function getAverageDailyVolumes(int $structureId, array $typeIds, \DateTimeImmutable $today): array
    {
        if ($typeIds === []) {
            return [];
        }

        $windowEnd = $today->format('Y-m-d');
        $windowStart = $today->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
        $window = ['structureId' => $structureId, 'windowStart' => $windowStart, 'windowEnd' => $windowEnd];

        $coveredDays = (int) $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT date) FROM structure_traded_volumes WHERE structure_id = :structureId AND date >= :windowStart AND date < :windowEnd',
            $window,
        );

        $averages = array_fill_keys($typeIds, null);
        if ($coveredDays < self::MIN_COVERED_DAYS) {
            return $averages;
        }

        $totals = $this->connection->fetchAllKeyValue(
            'SELECT type_id, SUM(traded_volume) FROM structure_traded_volumes WHERE structure_id = :structureId AND date >= :windowStart AND date < :windowEnd AND type_id IN (:typeIds) GROUP BY type_id',
            [...$window, 'typeIds' => $typeIds],
            ['typeIds' => ArrayParameterType::INTEGER],
        );

        foreach ($totals as $typeId => $total) {
            if ((int) $total > 0) {
                $averages[(int) $typeId] = (int) $total / (float) $coveredDays;
            }
        }

        return $averages;
    }

    /**
     * @param iterable<array<string, mixed>> $orders
     *
     * @return array<int, array{typeId: int, volumeRemain: int}>
     */
    private function orderStates(iterable $orders): array
    {
        $states = [];

        foreach ($orders as $order) {
            $states[$this->requireInt($order, 'order_id')] = [
                'typeId' => $this->requireInt($order, 'type_id'),
                'volumeRemain' => $this->requireInt($order, 'volume_remain'),
            ];
        }

        return $states;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function requireInt(array $order, string $field): int
    {
        if (!isset($order[$field]) || !is_int($order[$field])) {
            throw new \InvalidArgumentException(sprintf('ESI order has no integer "%s"', $field));
        }

        return $order[$field];
    }

    /**
     * @return array<int, array{typeId: int, volumeRemain: int}>|null
     */
    private function readableBaseline(mixed $baseline, int $structureId): ?array
    {
        if (is_array($baseline) && $this->isOrderStates($baseline)) {
            return $baseline;
        }

        $this->logger->warning('Structure traded volume baseline unreadable, ignored', [
            'structureId' => $structureId,
        ]);

        return null;
    }

    /**
     * @param array<mixed> $states
     */
    private function isOrderStates(array $states): bool
    {
        foreach ($states as $orderId => $state) {
            if (!is_int($orderId) || !is_array($state) || !is_int($state['typeId'] ?? null) || !is_int($state['volumeRemain'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, int> $tradedVolumeByType
     */
    private function accumulate(int $structureId, array $tradedVolumeByType, \DateTimeImmutable $syncedAt): void
    {
        if ($tradedVolumeByType === []) {
            return;
        }

        $date = $syncedAt->format('Y-m-d');

        foreach (array_chunk($tradedVolumeByType, self::UPSERT_BATCH_SIZE, true) as $batch) {
            $this->upsertBatch($structureId, $batch, $date);
        }

        $this->logger->info('Recorded structure traded volumes', [
            'structureId' => $structureId,
            'typeCount' => count($tradedVolumeByType),
            'date' => $date,
        ]);
    }

    /**
     * @param array<int, int> $batch
     */
    private function upsertBatch(int $structureId, array $batch, string $date): void
    {
        $placeholders = [];
        $parameters = [];

        foreach ($batch as $typeId => $tradedVolume) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            array_push($parameters, Uuid::v4()->toRfc4122(), $structureId, $typeId, $date, $tradedVolume);
        }

        $this->connection->executeStatement(
            'INSERT INTO structure_traded_volumes (id, structure_id, type_id, date, traded_volume) VALUES '
            . implode(', ', $placeholders)
            . ' ON CONFLICT (structure_id, type_id, date) DO UPDATE SET traded_volume = structure_traded_volumes.traded_volume + EXCLUDED.traded_volume',
            $parameters,
        );
    }
}
