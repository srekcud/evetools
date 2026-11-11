<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Market;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Repository\MarketPriceAlertRepository;
use App\Service\JitaMarketService;
use App\Service\MarketAlertPriceResolver;
use App\Service\StructureMarketService;
use App\State\Provider\Market\MarketAlertItemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketAlertItemProvider::class)]
class MarketAlertItemProviderTest extends TestCase
{
    public function testExposesTriggeredAtAndTriggeredPriceOfTriggeredAlert(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $alert = (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId(35)
            ->setTypeName('Pyerite')
            ->setDirection(AlertDirection::Below)
            ->setThreshold(8.5)
            ->setPriceSource(AlertPriceSource::JitaBuy)
            ->trigger(7.25);
        $alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $alertRepository->method('find')->willReturn($alert);

        $resource = (new MarketAlertItemProvider($security, $alertRepository, $this->priceResolver()))
            ->provide(new Delete(), ['id' => Uuid::v4()->toRfc4122()]);

        $this->assertSame(7.25, $resource->triggeredPrice);
        $this->assertSame($alert->getTriggeredAt()?->format('c'), $resource->triggeredAt);
        $this->assertNotNull($resource->triggeredAt);
        $this->assertSame('triggered', $resource->status);
    }

    #[Group('issue-632')]
    public function testGetExposesTheLiveCurrentPriceOfTheAlert(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $alert = (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId(34)
            ->setTypeName('Tritanium')
            ->setDirection(AlertDirection::Above)
            ->setThreshold(10.0)
            ->setPriceSource(AlertPriceSource::JitaSell);
        $alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $alertRepository->method('find')->willReturn($alert);

        $resource = (new MarketAlertItemProvider($security, $alertRepository, $this->priceResolver()))
            ->provide(new Get(), ['id' => Uuid::v4()->toRfc4122()]);

        $this->assertSame(12.0, $resource->currentPrice);
        $this->assertSame('jita_sell', $resource->priceSource);
    }

    private function priceResolver(): MarketAlertPriceResolver
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 12.0]);
        $jitaMarketService->method('getBuyPricesWithFallback')->willReturn([35 => 9.0]);

        return new MarketAlertPriceResolver($jitaMarketService, $this->createStub(StructureMarketService::class));
    }
}
