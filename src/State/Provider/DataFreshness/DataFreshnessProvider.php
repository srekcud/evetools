<?php

declare(strict_types=1);

namespace App\State\Provider\DataFreshness;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\DataFreshness\DataFreshnessResource;
use App\Service\Admin\SyncTracker;

/**
 * @implements ProviderInterface<DataFreshnessResource>
 */
class DataFreshnessProvider implements ProviderInterface
{
    private const SOURCES = ['market-jita', 'cost-indices', 'adjusted-prices'];

    public function __construct(
        private readonly SyncTracker $syncTracker,
    ) {
    }

    /**
     * @return list<DataFreshnessResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map($this->toResource(...), self::SOURCES);
    }

    private function toResource(string $source): DataFreshnessResource
    {
        $freshness = $this->syncTracker->getFreshness($source);

        $resource = new DataFreshnessResource();
        $resource->source = $source;
        $resource->lastSuccessAt = $freshness['lastSuccessAt'];
        $resource->expectedInterval = SyncTracker::EXPECTED_INTERVALS[$source];
        $resource->lastRunFailed = $freshness['lastRunFailed'];

        return $resource;
    }
}
