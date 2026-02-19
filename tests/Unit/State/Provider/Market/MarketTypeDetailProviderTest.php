<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Market\MarketTypeDetailResource;
use App\Entity\MarketFavorite;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use App\Service\StructureMarketService;
use App\State\Provider\Market\MarketTypeDetailProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(MarketTypeDetailProvider::class)]
class MarketTypeDetailProviderTest extends TestCase
{
    private Security&Stub $security;
    private InvTypeRepository&Stub $invTypeRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private StructureMarketService&Stub $structureMarketService;
    private MarketHistoryService&Stub $marketHistoryService;
    private MarketFavoriteRepository&Stub $favoriteRepository;
    private MarketTypeDetailProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->structureMarketService = $this->createStub(StructureMarketService::class);
        $this->marketHistoryService = $this->createStub(MarketHistoryService::class);
        $this->favoriteRepository = $this->createStub(MarketFavoriteRepository::class);

        $this->provider = new MarketTypeDetailProvider(
            $this->security,
            $this->invTypeRepository,
            $this->jitaMarketService,
            $this->structureMarketService,
            $this->marketHistoryService,
            $this->favoriteRepository,
        );
    }

    // ===========================================
    // Response structure
    // ===========================================

    public function testReturnsMarketTypeDetailResourceWithCorrectProperties(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00]);
        $this->jitaMarketService->method('getOrderBooksWithFallback')->willReturn([
            'sell' => [['price' => 5.50, 'volume' => 1000]],
            'buy' => [['price' => 5.00, 'volume' => 2000]],
        ]);
        $this->jitaMarketService->method('getAverageDailyVolumes')->willReturn([34 => 500000.0]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(3.5);
        $this->favoriteRepository->method('findByUserAndType')->willReturn(null);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertInstanceOf(MarketTypeDetailResource::class, $result);
        $this->assertSame(34, $result->typeId);
        $this->assertSame('Tritanium', $result->typeName);
        $this->assertSame('Mineral', $result->groupName);
        $this->assertSame('Material', $result->categoryName);
        $this->assertSame(5.50, $result->jitaSell);
        $this->assertSame(5.00, $result->jitaBuy);
        $this->assertSame(500000.0, $result->avgDailyVolume);
        $this->assertSame(3.5, $result->change30d);
        $this->assertFalse($result->isFavorite);
    }

    // ===========================================
    // Spread calculation
    // ===========================================

    public function testSpreadIsCalculated(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 200.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 150.0]);
        $this->jitaMarketService->method('getOrderBooksWithFallback')->willReturn(['sell' => [], 'buy' => []]);
        $this->jitaMarketService->method('getAverageDailyVolumes')->willReturn([]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);
        $this->favoriteRepository->method('findByUserAndType')->willReturn(null);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        // Spread = (200 - 150) / 200 * 100 = 25.0%
        $this->assertSame(25.0, $result->spread);
    }

    // ===========================================
    // Favorite flag
    // ===========================================

    public function testIsFavoriteIsTrueWhenFavoriteExists(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $invType = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 4.0]);
        $this->jitaMarketService->method('getOrderBooksWithFallback')->willReturn(['sell' => [], 'buy' => []]);
        $this->jitaMarketService->method('getAverageDailyVolumes')->willReturn([]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);

        $favorite = $this->createStub(MarketFavorite::class);
        $this->favoriteRepository->method('findByUserAndType')->willReturn($favorite);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertTrue($result->isFavorite);
    }

    // ===========================================
    // Structure price
    // ===========================================

    public function testStructureSellIsPopulatedWhenUserHasPreferredStructure(): void
    {
        $user = $this->createUserStub(preferredStructureId: 1_000_000_000_123);
        $this->security->method('getUser')->willReturn($user);

        $invType = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 4.0]);
        $this->jitaMarketService->method('getOrderBooksWithFallback')->willReturn(['sell' => [], 'buy' => []]);
        $this->jitaMarketService->method('getAverageDailyVolumes')->willReturn([]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);
        $this->favoriteRepository->method('findByUserAndType')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(4.80);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertSame(4.80, $result->structureSell);
    }

    public function testStructureSellIsNullWhenNoPreferredStructure(): void
    {
        $user = $this->createUserStub(preferredStructureId: null);
        $this->security->method('getUser')->willReturn($user);

        $invType = $this->createInvType(34, 'Tritanium', 'Mineral', 'Material');
        $this->invTypeRepository->method('findByTypeId')->willReturn($invType);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 4.0]);
        $this->jitaMarketService->method('getOrderBooksWithFallback')->willReturn(['sell' => [], 'buy' => []]);
        $this->jitaMarketService->method('getAverageDailyVolumes')->willReturn([]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);
        $this->favoriteRepository->method('findByUserAndType')->willReturn(null);

        $result = $this->provider->provide(new Get(), ['typeId' => 34]);

        $this->assertNull($result->structureSell);
    }

    // ===========================================
    // Error cases
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new Get(), ['typeId' => 34]);
    }

    public function testThrowsNotFoundWhenTypeDoesNotExist(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->invTypeRepository->method('findByTypeId')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Get(), ['typeId' => 99999]);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(?int $preferredStructureId = null): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getPreferredMarketStructureId')->willReturn($preferredStructureId);

        return $user;
    }

    private function createInvType(int $typeId, string $typeName, string $groupName, string $categoryName): InvType
    {
        $category = new InvCategory();
        $category->setCategoryName($categoryName);

        $group = new InvGroup();
        $group->setGroupName($groupName);
        $group->setCategory($category);

        $type = new InvType();
        $type->setTypeId($typeId);
        $type->setTypeName($typeName);
        $type->setGroup($group);

        return $type;
    }
}
