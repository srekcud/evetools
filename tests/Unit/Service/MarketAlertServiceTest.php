<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Repository\MarketPriceAlertRepository;
use App\Service\JitaMarketService;
use App\Service\MarketAlertService;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketAlertService::class)]
class MarketAlertServiceTest extends TestCase
{
    private MarketPriceAlertRepository&Stub $alertRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private EntityManagerInterface&MockObject $em;
    private HubInterface&MockObject $hub;
    private MarketAlertService $service;

    protected function setUp(): void
    {
        $this->alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $mercurePublisher = new MercurePublisherService($this->hub, new NullLogger());

        $this->service = new MarketAlertService(
            $this->alertRepository,
            $this->jitaMarketService,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
        );
    }

    // ===========================================
    // checkAlerts — trigger conditions
    // ===========================================

    public function testAlertTriggersWhenPriceGoesAboveThreshold(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_ABOVE, 10.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 12.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 9.0]);
        $this->em->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(1, $triggered);
        $this->assertSame(MarketPriceAlert::STATUS_TRIGGERED, $alert->getStatus());
        $this->assertNotNull($alert->getTriggeredAt());
    }

    public function testAlertTriggersWhenPriceGoesBelowThreshold(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_BELOW, 10.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 8.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 7.0]);
        $this->em->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(1, $triggered);
        $this->assertSame(MarketPriceAlert::STATUS_TRIGGERED, $alert->getStatus());
    }

    public function testAlertTriggersOnBuyPriceSource(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_ABOVE, 8.0, MarketPriceAlert::SOURCE_JITA_BUY);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 12.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 9.0]);
        $this->em->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(1, $triggered);
    }

    // ===========================================
    // checkAlerts — no trigger
    // ===========================================

    public function testAlertDoesNotTriggerWhenConditionNotMet(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_ABOVE, 15.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 10.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 8.0]);
        $this->em->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(0, $triggered);
        $this->assertSame(MarketPriceAlert::STATUS_ACTIVE, $alert->getStatus());
    }

    public function testAlertDoesNotTriggerBelowWhenPriceIsHigher(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_BELOW, 5.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 10.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 8.0]);
        $this->em->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(0, $triggered);
    }

    // ===========================================
    // checkAlerts — no price data
    // ===========================================

    public function testAlertWithNoJitaPriceDataIsSkipped(): void
    {
        $alert = $this->createAlert(99999, 'Unknown Item', MarketPriceAlert::DIRECTION_ABOVE, 10.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([99999 => null]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([99999 => null]);
        $this->em->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(0, $triggered);
        $this->assertSame(MarketPriceAlert::STATUS_ACTIVE, $alert->getStatus());
    }

    // ===========================================
    // checkAlerts — empty alerts
    // ===========================================

    public function testNoActiveAlertsReturnsZero(): void
    {
        $this->alertRepository->method('findActiveAlerts')->willReturn([]);
        $this->em->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(0, $triggered);
    }

    // ===========================================
    // checkAlerts — multiple alerts, mixed results
    // ===========================================

    public function testMultipleAlertsMixedTriggerResults(): void
    {
        $alertTriggered = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_ABOVE, 5.0, MarketPriceAlert::SOURCE_JITA_SELL);
        $alertNotTriggered = $this->createAlert(35, 'Pyerite', MarketPriceAlert::DIRECTION_ABOVE, 20.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alertTriggered, $alertNotTriggered]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 10.0, 35 => 15.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 8.0, 35 => 12.0]);
        $this->em->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(1, $triggered);
        $this->assertSame(MarketPriceAlert::STATUS_TRIGGERED, $alertTriggered->getStatus());
        $this->assertSame(MarketPriceAlert::STATUS_ACTIVE, $alertNotTriggered->getStatus());
    }

    // ===========================================
    // checkAlerts — edge: price exactly at threshold
    // ===========================================

    public function testAlertTriggersWhenPriceExactlyAtThresholdAbove(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_ABOVE, 10.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 10.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 8.0]);
        $this->em->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(1, $triggered);
    }

    public function testAlertTriggersWhenPriceExactlyAtThresholdBelow(): void
    {
        $alert = $this->createAlert(34, 'Tritanium', MarketPriceAlert::DIRECTION_BELOW, 10.0, MarketPriceAlert::SOURCE_JITA_SELL);

        $this->alertRepository->method('findActiveAlerts')->willReturn([$alert]);
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 10.0]);
        $this->jitaMarketService->method('getBuyPrices')->willReturn([34 => 8.0]);
        $this->em->expects($this->once())->method('flush');
        $this->hub->expects($this->once())->method('publish');

        $triggered = $this->service->checkAlerts();

        $this->assertSame(1, $triggered);
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
        int $typeId,
        string $typeName,
        string $direction,
        float $threshold,
        string $priceSource,
    ): MarketPriceAlert {
        $alert = new MarketPriceAlert();
        $alert->setUser($this->createUserStub());
        $alert->setTypeId($typeId);
        $alert->setTypeName($typeName);
        $alert->setDirection($direction);
        $alert->setThreshold($threshold);
        $alert->setPriceSource($priceSource);

        return $alert;
    }
}
