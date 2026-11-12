<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\DataFreshness;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\DataFreshness\DataFreshnessResource;
use App\Service\Admin\SyncTracker;
use App\Tests\Unit\Service\Admin\InMemorySyncRunLog;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\DataFreshness\DataFreshnessProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(DataFreshnessProvider::class)]
#[Group('issue-236')]
final class DataFreshnessProviderTest extends TestCase
{
    private SyncTracker $syncTracker;
    private DataFreshnessProvider $provider;

    protected function setUp(): void
    {
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            new InMemorySyncRunLog(),
        );
        $this->provider = new DataFreshnessProvider($this->syncTracker);
    }

    public function testGlobalSyncsNeverRunAreListedInOrderWithTheirExpectedIntervals(): void
    {
        $this->assertSame(
            [
                ['source' => 'market-jita', 'lastSuccessAt' => null, 'expectedInterval' => 3600, 'lastRunFailed' => false],
                ['source' => 'cost-indices', 'lastSuccessAt' => null, 'expectedInterval' => 7200, 'lastRunFailed' => false],
                ['source' => 'adjusted-prices', 'lastSuccessAt' => null, 'expectedInterval' => 86400, 'lastRunFailed' => false],
            ],
            $this->providedFreshness(),
        );
    }

    public function testEachSourceExpectedIntervalIsTheScheduledIntervalOfItsSync(): void
    {
        foreach ($this->providedFreshness() as $freshness) {
            $this->assertArrayHasKey($freshness['source'], SyncTracker::EXPECTED_INTERVALS);
            $this->assertSame(SyncTracker::EXPECTED_INTERVALS[$freshness['source']], $freshness['expectedInterval']);
        }
    }

    public function testFailedMarketJitaRunIsReportedOnItsSourceOnly(): void
    {
        $this->syncTracker->fail('market-jita', 'ESI 502 Bad Gateway');
        $this->syncTracker->complete('adjusted-prices');

        $this->assertSame(
            [['market-jita', true], ['cost-indices', false], ['adjusted-prices', false]],
            array_map(
                static fn (array $freshness): array => [$freshness['source'], $freshness['lastRunFailed']],
                $this->providedFreshness(),
            ),
        );
        $this->assertSame(
            [null, null],
            [$this->providedFreshness()[0]['lastSuccessAt'], $this->providedFreshness()[1]['lastSuccessAt']],
        );
        $this->assertSame(
            $this->syncTracker->getFreshness('adjusted-prices')['lastSuccessAt'],
            $this->providedFreshness()[2]['lastSuccessAt'],
        );
        $this->assertNotNull($this->providedFreshness()[2]['lastSuccessAt']);
    }

    private function providedFreshness(): array
    {
        $resources = $this->provider->provide(new GetCollection());
        $this->assertIsIterable($resources);

        $freshness = [];
        foreach ($resources as $resource) {
            $this->assertInstanceOf(DataFreshnessResource::class, $resource);
            $freshness[] = [
                'source' => $resource->source,
                'lastSuccessAt' => $resource->lastSuccessAt,
                'expectedInterval' => $resource->expectedInterval,
                'lastRunFailed' => $resource->lastRunFailed,
            ];
        }

        return $freshness;
    }
}
