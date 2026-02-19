<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Market\MarketSearchResource;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvMarketGroup;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use App\State\Provider\Market\MarketSearchProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(MarketSearchProvider::class)]
class MarketSearchProviderTest extends TestCase
{
    private InvTypeRepository&Stub $invTypeRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private MarketHistoryService&Stub $marketHistoryService;
    private RequestStack $requestStack;
    private MarketSearchProvider $provider;

    protected function setUp(): void
    {
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->marketHistoryService = $this->createStub(MarketHistoryService::class);
        $this->requestStack = new RequestStack();

        $this->provider = new MarketSearchProvider(
            $this->invTypeRepository,
            $this->jitaMarketService,
            $this->marketHistoryService,
            $this->requestStack,
        );
    }

    // ===========================================
    // Response structure
    // ===========================================

    public function testReturnsMarketSearchResourceWithResultsProperty(): void
    {
        $request = new Request(['q' => 'Tritanium']);
        $this->requestStack->push($request);

        $type = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('searchByName')->willReturn([$type]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(2.5);

        $result = $this->provider->provide(new Get());

        $this->assertInstanceOf(MarketSearchResource::class, $result);
        $this->assertIsArray($result->results);
        $this->assertCount(1, $result->results);
    }

    public function testSearchItemResourceHasCorrectProperties(): void
    {
        $request = new Request(['q' => 'Tritanium']);
        $this->requestStack->push($request);

        $type = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('searchByName')->willReturn([$type]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 10.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 8.0]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(-1.5);

        $result = $this->provider->provide(new Get());
        $item = $result->results[0];

        $this->assertSame(34, $item->typeId);
        $this->assertSame('Tritanium', $item->typeName);
        $this->assertSame('Mineral', $item->groupName);
        $this->assertSame('Material', $item->categoryName);
        $this->assertSame(10.0, $item->jitaSell);
        $this->assertSame(8.0, $item->jitaBuy);
        $this->assertSame(-1.5, $item->change30d);
    }

    // ===========================================
    // Spread calculation
    // ===========================================

    public function testSpreadIsCalculatedCorrectly(): void
    {
        $request = new Request(['q' => 'Tritanium']);
        $this->requestStack->push($request);

        $type = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('searchByName')->willReturn([$type]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 100.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 80.0]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);

        $result = $this->provider->provide(new Get());

        // Spread = (100 - 80) / 100 * 100 = 20.0%
        $this->assertSame(20.0, $result->results[0]->spread);
    }

    public function testSpreadIsNullWhenSellPriceIsNull(): void
    {
        $request = new Request(['q' => 'Tritanium']);
        $this->requestStack->push($request);

        $type = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('searchByName')->willReturn([$type]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => null]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.0]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);

        $result = $this->provider->provide(new Get());

        $this->assertNull($result->results[0]->spread);
    }

    // ===========================================
    // Short query (< 2 chars)
    // ===========================================

    public function testReturnsEmptyResultsForShortQuery(): void
    {
        $request = new Request(['q' => 'T']);
        $this->requestStack->push($request);

        $result = $this->provider->provide(new Get());

        $this->assertInstanceOf(MarketSearchResource::class, $result);
        $this->assertSame([], $result->results);
    }

    public function testReturnsEmptyResultsWhenNoQuery(): void
    {
        $request = new Request();
        $this->requestStack->push($request);

        $result = $this->provider->provide(new Get());

        $this->assertSame([], $result->results);
    }

    // ===========================================
    // Filtering non-marketable types
    // ===========================================

    public function testFiltersOutTypesWithoutMarketGroup(): void
    {
        $request = new Request(['q' => 'Test']);
        $this->requestStack->push($request);

        $marketable = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $nonMarketable = $this->createInvType(99999, 'NonMarketable Item', 'Test', 'Test', false);

        $this->invTypeRepository->method('searchByName')->willReturn([$marketable, $nonMarketable]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 4.0]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);

        $result = $this->provider->provide(new Get());

        $this->assertCount(1, $result->results);
        $this->assertSame(34, $result->results[0]->typeId);
    }

    public function testReturnsEmptyWhenAllTypesAreNonMarketable(): void
    {
        $request = new Request(['q' => 'Test']);
        $this->requestStack->push($request);

        $nonMarketable = $this->createInvType(99999, 'NonMarketable', 'Test', 'Test', false);
        $this->invTypeRepository->method('searchByName')->willReturn([$nonMarketable]);

        $result = $this->provider->provide(new Get());

        $this->assertSame([], $result->results);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createInvType(
        int $typeId,
        string $typeName,
        string $groupName,
        string $categoryName,
        bool $hasMarketGroup = true,
    ): InvType {
        $category = new InvCategory();
        $category->setCategoryName($categoryName);

        $group = new InvGroup();
        $group->setGroupName($groupName);
        $group->setCategory($category);

        $type = new InvType();
        $type->setTypeId($typeId);
        $type->setTypeName($typeName);
        $type->setGroup($group);

        if ($hasMarketGroup) {
            $marketGroup = new InvMarketGroup();
            $marketGroup->setMarketGroupId(1);
            $marketGroup->setMarketGroupName('Test Market Group');
            $type->setMarketGroup($marketGroup);
        }

        return $type;
    }
}
