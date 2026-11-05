<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StructureTradedVolumeService
{
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
    }

    /**
     * @param list<int> $typeIds
     *
     * @return array<int, float|null>
     */
    public function getAverageDailyVolumes(int $structureId, array $typeIds, \DateTimeImmutable $today): array
    {
        return array_fill_keys($typeIds, null);
    }
}
