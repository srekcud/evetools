<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class StructureMarketSnapshotService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record daily snapshots for a structure via batch UPSERT.
     *
     * @param array<int, array{sellMin: float|null, buyMax: float|null, sellOrderCount: int, buyOrderCount: int, sellVolume: int, buyVolume: int}> $aggregates typeId => aggregate data
     */
    public function recordSnapshots(int $structureId, array $aggregates): void
    {
        if (empty($aggregates)) {
            return;
        }

        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();

        $sql = <<<'SQL'
            INSERT INTO structure_market_snapshots
                (id, structure_id, type_id, date, sell_min, buy_max, sell_order_count, buy_order_count, sell_volume, buy_volume, created_at, updated_at)
            VALUES
                (:id, :sid, :tid, :date, :sell_min, :buy_max, :sell_oc, :buy_oc, :sell_vol, :buy_vol, :now, :now)
            ON CONFLICT (structure_id, type_id, date)
            DO UPDATE SET
                sell_min = :sell_min,
                buy_max = :buy_max,
                sell_order_count = :sell_oc,
                buy_order_count = :buy_oc,
                sell_volume = :sell_vol,
                buy_volume = :buy_vol,
                updated_at = :now
            SQL;

        $stmt = $this->connection->prepare($sql);
        $dateStr = $today->format('Y-m-d');
        $nowStr = $now->format('Y-m-d H:i:s');

        foreach ($aggregates as $typeId => $data) {
            $stmt->bindValue('id', Uuid::v4()->toRfc4122());
            $stmt->bindValue('sid', $structureId);
            $stmt->bindValue('tid', $typeId);
            $stmt->bindValue('date', $dateStr);
            $stmt->bindValue('sell_min', $data['sellMin']);
            $stmt->bindValue('buy_max', $data['buyMax']);
            $stmt->bindValue('sell_oc', $data['sellOrderCount']);
            $stmt->bindValue('buy_oc', $data['buyOrderCount']);
            $stmt->bindValue('sell_vol', $data['sellVolume']);
            $stmt->bindValue('buy_vol', $data['buyVolume']);
            $stmt->bindValue('now', $nowStr);
            $stmt->executeStatement();
        }

        $this->logger->info('Recorded structure market snapshots', [
            'structureId' => $structureId,
            'typeCount' => count($aggregates),
            'date' => $dateStr,
        ]);
    }
}
