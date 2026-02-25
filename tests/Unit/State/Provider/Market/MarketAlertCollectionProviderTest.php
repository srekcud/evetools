<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Market\MarketAlertResource;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use App\Repository\MarketPriceAlertRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\State\Provider\Market\MarketAlertCollectionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketAlertCollectionProvider::class)]
class MarketAlertCollectionProviderTest extends TestCase
{
    private Security&Stub $security;
    private MarketPriceAlertRepository&Stub $alertRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private StructureMarketService&Stub $structureMarketService;
    private MarketAlertCollectionProvider $provider;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->structureMarketService = $this->createStub(StructureMarketService::class);

        $this->provider = new MarketAlertCollectionProvider(
            $this->security,
            $this->alertRepository,
            $this->jitaMarketService,
            $this->structureMarketService,
        );
    }

    // ===========================================
    // Response structure
    // ===========================================

    public function testReturnsArrayOfMarketAlertResources(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $alert = $this->createAlert($user, 34, 'Tritanium', AlertDirection::Above, 10.0, AlertPriceSource::JitaSell);
        $this->alertRepository->method('findByUser')->willReturn([$alert]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 9.0]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(MarketAlertResource::class, $result[0]);
    }

    public function testAlertResourceHasCorrectProperties(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $alert = $this->createAlert($user, 34, 'Tritanium', AlertDirection::Above, 10.0, AlertPriceSource::JitaSell);
        $this->alertRepository->method('findByUser')->willReturn([$alert]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 9.0]);

        $result = $this->provider->provide(new GetCollection());
        $resource = $result[0];

        $this->assertSame(34, $resource->typeId);
        $this->assertSame('Tritanium', $resource->typeName);
        $this->assertSame('above', $resource->direction);
        $this->assertSame(10.0, $resource->threshold);
        $this->assertSame('jita_sell', $resource->priceSource);
        $this->assertSame('active', $resource->status);
        $this->assertNull($resource->triggeredAt);
        $this->assertNotEmpty($resource->createdAt);
    }

    // ===========================================
    // Current price enrichment
    // ===========================================

    public function testCurrentPriceUsesJitaSellWhenSourceIsJitaSell(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $alert = $this->createAlert($user, 34, 'Tritanium', AlertDirection::Above, 10.0, AlertPriceSource::JitaSell);
        $this->alertRepository->method('findByUser')->willReturn([$alert]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 9.0]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame(12.0, $result[0]->currentPrice);
    }

    public function testCurrentPriceUsesJitaBuyWhenSourceIsJitaBuy(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $alert = $this->createAlert($user, 34, 'Tritanium', AlertDirection::Below, 8.0, AlertPriceSource::JitaBuy);
        $this->alertRepository->method('findByUser')->willReturn([$alert]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 9.0]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame(9.0, $result[0]->currentPrice);
    }

    // ===========================================
    // Empty alerts
    // ===========================================

    public function testReturnsEmptyArrayWhenUserHasNoAlerts(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);
        $this->alertRepository->method('findByUser')->willReturn([]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertSame([], $result);
    }

    // ===========================================
    // Multiple alerts with different types
    // ===========================================

    public function testBatchLoadsPricesForMultipleAlerts(): void
    {
        $user = $this->createUserStub();
        $this->security->method('getUser')->willReturn($user);

        $alert1 = $this->createAlert($user, 34, 'Tritanium', AlertDirection::Above, 10.0, AlertPriceSource::JitaSell);
        $alert2 = $this->createAlert($user, 35, 'Pyerite', AlertDirection::Below, 20.0, AlertPriceSource::JitaBuy);

        $this->alertRepository->method('findByUser')->willReturn([$alert1, $alert2]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0, 35 => 15.0]);
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturn([34 => 9.0, 35 => 13.0]);

        $result = $this->provider->provide(new GetCollection());

        $this->assertCount(2, $result);
        $this->assertSame(12.0, $result[0]->currentPrice); // jita_sell for Tritanium
        $this->assertSame(13.0, $result[1]->currentPrice); // jita_buy for Pyerite
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

    private function createAlert(
        User $user,
        int $typeId,
        string $typeName,
        AlertDirection $direction,
        float $threshold,
        AlertPriceSource $priceSource,
    ): MarketPriceAlert {
        $alert = new MarketPriceAlert();
        $alert->setUser($user);
        $alert->setTypeId($typeId);
        $alert->setTypeName($typeName);
        $alert->setDirection($direction);
        $alert->setThreshold($threshold);
        $alert->setPriceSource($priceSource);

        return $alert;
    }
}
