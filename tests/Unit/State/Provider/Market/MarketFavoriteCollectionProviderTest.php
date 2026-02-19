<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Market\MarketFavoriteResource;
use App\Entity\MarketFavorite;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\MarketFavoriteRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use App\State\Provider\Market\MarketFavoriteCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketFavoriteCollectionProvider::class)]
class MarketFavoriteCollectionProviderTest extends TestCase
{
    private Security&Stub $security;
    private MarketFavoriteRepository&Stub $favoriteRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private MarketHistoryService&Stub $marketHistoryService;
    private MarketFavoriteCollectionProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->favoriteRepository = $this->createStub(MarketFavoriteRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->marketHistoryService = $this->createStub(MarketHistoryService::class);

        $this->provider = new MarketFavoriteCollectionProvider(
            $this->security,
            $this->favoriteRepository,
            $this->invTypeRepository,
            $this->jitaMarketService,
            $this->marketHistoryService,
        );
    }

    // ===========================================
    // Response structure
    // ===========================================

    public function testReturnsArrayOfMarketFavoriteResources(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $favorite = $this->createFavorite(34, $user);
        $this->favoriteRepository->method('findByUser')->willReturn([$favorite]);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeIds')->willReturn([34 => $invType]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(2.0);

        $result = $this->provider->provide(new GetCollection());

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(MarketFavoriteResource::class, $result[0]);
    }

    public function testFavoriteResourceHasCorrectProperties(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $favorite = $this->createFavorite(34, $user);
        $this->favoriteRepository->method('findByUser')->willReturn([$favorite]);

        $invType = new InvType();
        $invType->setTypeId(34);
        $invType->setTypeName('Tritanium');
        $this->invTypeRepository->method('findByTypeIds')->willReturn([34 => $invType]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(2.0);

        $result = $this->provider->provide(new GetCollection());
        $resource = $result[0];

        $this->assertSame(34, $resource->typeId);
        $this->assertSame('Tritanium', $resource->typeName);
        $this->assertSame(5.50, $resource->jitaSell);
        $this->assertSame(5.00, $resource->jitaBuy);
        $this->assertSame(2.0, $resource->change30d);
        $this->assertNotEmpty($resource->createdAt);
    }

    // ===========================================
    // Fallback type name
    // ===========================================

    public function testFallbackTypeNameWhenSdeTypeNotFound(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $favorite = $this->createFavorite(99999, $user);
        $this->favoriteRepository->method('findByUser')->willReturn([$favorite]);

        // No SDE type found
        $this->invTypeRepository->method('findByTypeIds')->willReturn([]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([99999 => null]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([99999 => null]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(null);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame('Type #99999', $result[0]->typeName);
    }

    // ===========================================
    // Empty favorites
    // ===========================================

    public function testReturnsEmptyArrayWhenUserHasNoFavorites(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->favoriteRepository->method('findByUser')->willReturn([]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([], $result);
    }

    // ===========================================
    // Multiple favorites
    // ===========================================

    public function testReturnsMultipleFavoritesWithPrices(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $fav1 = $this->createFavorite(34, $user);
        $fav2 = $this->createFavorite(35, $user);
        $this->favoriteRepository->method('findByUser')->willReturn([$fav1, $fav2]);

        $type34 = new InvType();
        $type34->setTypeId(34);
        $type34->setTypeName('Tritanium');
        $type35 = new InvType();
        $type35->setTypeId(35);
        $type35->setTypeName('Pyerite');
        $this->invTypeRepository->method('findByTypeIds')->willReturn([34 => $type34, 35 => $type35]);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.50, 35 => 8.00]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 5.00, 35 => 7.00]);
        $this->marketHistoryService->method('get30dPriceChange')->willReturn(1.0);

        $result = $this->provider->provide(new GetCollection());

        $this->assertCount(2, $result);
        $this->assertSame('Tritanium', $result[0]->typeName);
        $this->assertSame('Pyerite', $result[1]->typeName);
    }

    // ===========================================
    // Auth
    // ===========================================

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new GetCollection());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserStub(): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        return $user;
    }

    private function createFavorite(int $typeId, User $user): MarketFavorite
    {
        $favorite = new MarketFavorite();
        $favorite->setUser($user);
        $favorite->setTypeId($typeId);

        return $favorite;
    }
}
