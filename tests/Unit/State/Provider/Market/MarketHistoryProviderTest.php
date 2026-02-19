<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Market\MarketHistoryEntryResource;
use App\ApiResource\Market\MarketHistoryResource;
use App\Entity\MarketPriceHistory;
use App\Repository\StructureMarketSnapshotRepository;
use App\Service\MarketHistoryService;
use App\State\Provider\Market\MarketHistoryProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MarketHistoryProvider::class)]
#[AllowMockObjectsWithoutExpectations]
class MarketHistoryProviderTest extends TestCase
{
    private MarketHistoryService&MockObject $marketHistoryService;
    private StructureMarketSnapshotRepository&MockObject $snapshotRepository;
    private Security&MockObject $security;
    private RequestStack $requestStack;
    private MarketHistoryProvider $provider;

    protected function setUp(): void
    {
        $this->marketHistoryService = $this->createMock(MarketHistoryService::class);
        $this->snapshotRepository = $this->createMock(StructureMarketSnapshotRepository::class);
        $this->security = $this->createMock(Security::class);
        $this->requestStack = new RequestStack();

        $this->provider = new MarketHistoryProvider(
            $this->marketHistoryService,
            $this->snapshotRepository,
            $this->requestStack,
            $this->security,
            1234567890,
            'Test Structure',
        );
    }

    // ===========================================
    // Response structure
    // ===========================================

    public function testReturnsMarketHistoryResourceWithEntriesProperty(): void
    {
        $request = new Request(['days' => '30']);
        $this->requestStack->push($request);

        $history = $this->createHistoryEntry(34, '2026-02-15', 5.5, 6.0, 5.0, 1000, 50000);
        $this->marketHistoryService->method('getHistory')->willReturn([$history]);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertInstanceOf(MarketHistoryResource::class, $result);
        $this->assertSame(34, $result->typeId);
        $this->assertIsArray($result->entries);
        $this->assertCount(1, $result->entries);
    }

    public function testEntryResourceHasCorrectProperties(): void
    {
        $request = new Request(['days' => '30']);
        $this->requestStack->push($request);

        $history = $this->createHistoryEntry(34, '2026-02-15', 5.5, 6.0, 5.0, 1000, 50000);
        $this->marketHistoryService->method('getHistory')->willReturn([$history]);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);
        $entry = $result->entries[0];

        $this->assertInstanceOf(MarketHistoryEntryResource::class, $entry);
        $this->assertSame('2026-02-15', $entry->date);
        $this->assertSame(5.5, $entry->average);
        $this->assertSame(6.0, $entry->highest);
        $this->assertSame(5.0, $entry->lowest);
        $this->assertSame(1000, $entry->orderCount);
        $this->assertSame(50000, $entry->volume);
    }

    // ===========================================
    // Multiple entries
    // ===========================================

    public function testReturnsMultipleEntries(): void
    {
        $request = new Request(['days' => '7']);
        $this->requestStack->push($request);

        $entries = [
            $this->createHistoryEntry(34, '2026-02-14', 5.3, 5.8, 5.0, 900, 40000),
            $this->createHistoryEntry(34, '2026-02-15', 5.5, 6.0, 5.0, 1000, 50000),
        ];
        $this->marketHistoryService->method('getHistory')->willReturn($entries);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertCount(2, $result->entries);
        $this->assertSame('2026-02-14', $result->entries[0]->date);
        $this->assertSame('2026-02-15', $result->entries[1]->date);
    }

    // ===========================================
    // Empty history
    // ===========================================

    public function testReturnsEmptyEntriesWhenNoHistory(): void
    {
        $request = new Request(['days' => '30']);
        $this->requestStack->push($request);

        $this->marketHistoryService->method('getHistory')->willReturn([]);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertSame([], $result->entries);
        $this->assertSame(34, $result->typeId);
    }

    // ===========================================
    // Days parameter clamping
    // ===========================================

    public function testDaysDefaultsTo30(): void
    {
        $request = new Request();
        $this->requestStack->push($request);

        $this->marketHistoryService->method('getHistory')
            ->with(34, 30)
            ->willReturn([]);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertInstanceOf(MarketHistoryResource::class, $result);
    }

    public function testDaysIsClampedToMinimum1(): void
    {
        $request = new Request(['days' => '0']);
        $this->requestStack->push($request);

        $this->marketHistoryService->method('getHistory')
            ->with(34, 1)
            ->willReturn([]);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertInstanceOf(MarketHistoryResource::class, $result);
    }

    public function testDaysIsClampedToMaximum365(): void
    {
        $request = new Request(['days' => '999']);
        $this->requestStack->push($request);

        $this->marketHistoryService->method('getHistory')
            ->with(34, 365)
            ->willReturn([]);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertInstanceOf(MarketHistoryResource::class, $result);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createHistoryEntry(
        int $typeId,
        string $date,
        float $average,
        float $highest,
        float $lowest,
        int $orderCount,
        int $volume,
    ): MarketPriceHistory {
        $entry = new MarketPriceHistory();
        $entry->setTypeId($typeId);
        $entry->setDate(new \DateTime($date));
        $entry->setAverage($average);
        $entry->setHighest($highest);
        $entry->setLowest($lowest);
        $entry->setOrderCount($orderCount);
        $entry->setVolume($volume);

        return $entry;
    }
}
