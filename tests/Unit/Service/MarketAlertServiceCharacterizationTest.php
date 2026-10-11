<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertDirection;
use App\Enum\AlertPriceSource;
use App\Enum\AlertStatus;
use App\Repository\MarketPriceAlertRepository;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Service\MarketAlertService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\StructureMarketService;
use App\Service\StructureMarketSnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MarketAlertService::class)]
final class MarketAlertServiceCharacterizationTest extends TestCase
{
    private const string USER_ID = '0192a7c4-7b3d-7f1e-8a2c-5d6e7f8a9b01';
    private const string OTHER_USER_ID = '0192a7c4-8c4e-7a2f-9b3d-6e7f8a9b0c12';
    private const string ALERT_ID = '0192a7c4-9d5f-7b30-8c4e-7f8a9b0c1d23';

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    private const int STRUCTURE_AT_CREATION = 1035466617946;
    private const int STRUCTURE_PREFERRED_NOW = 1028858195912;
    private const int STRUCTURE_WITHOUT_CACHE = 1043136314480;

    private MarketPriceAlertRepository&Stub $alertRepository;
    private JitaMarketService&Stub $jitaMarketService;
    private ArrayAdapter $structureCache;
    private BufferingLogger $logger;
    private MercurePublisherService $mercurePublisher;
    private StructureMarketService $structureMarketService;
    private MarketAlertService $service;

    private array $publishedUpdates = [];

    private array $requestedJitaTypeIds = [];

    protected function setUp(): void
    {
        $this->alertRepository = $this->createStub(MarketPriceAlertRepository::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->structureCache = new ArrayAdapter();
        $this->logger = new BufferingLogger();

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:' . Uuid::v4()->toRfc4122();
        });
        $this->mercurePublisher = new MercurePublisherService($hub, new NullLogger());

        $this->structureMarketService = new StructureMarketService(
            $this->createStub(EsiClient::class),
            $this->structureCache,
            new NullLogger(),
            $this->mercurePublisher,
            $this->createStub(StructureMarketSnapshotService::class),
        );

        $this->service = $this->createService($this->createStub(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testJitaAlertsAreEvaluatedOnTheSideOfTheirPriceSourceWithTheThresholdIncluded(): void
    {
        $user = $this->createUser(self::USER_ID, null);
        $tritaniumAboveOnSell = $this->createAlert($user, self::TRITANIUM, 'Tritanium', AlertDirection::Above, 4.375, AlertPriceSource::JitaSell);
        $pyeriteBelowOnBuy = $this->createAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);
        $mexallonBelowOnSellAtThreshold = $this->createAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Below, 61.5, AlertPriceSource::JitaSell);
        $isogenAboveOnBuyJustUnder = $this->createAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 47.0, AlertPriceSource::JitaBuy);
        $tritaniumBelowOnSell = $this->createAlert($user, self::TRITANIUM, 'Tritanium', AlertDirection::Below, 4.0625, AlertPriceSource::JitaSell);
        $alerts = [$tritaniumAboveOnSell, $tritaniumBelowOnSell, $pyeriteBelowOnBuy, $mexallonBelowOnSellAtThreshold, $isogenAboveOnBuyJustUnder];
        $this->givenActiveAlertsAndJitaPrices(
            $alerts,
            [self::TRITANIUM => 4.5, self::PYERITE => 10.25, self::MEXALLON => 61.5, self::ISOGEN => 52.5],
            [self::TRITANIUM => 3.9375, self::PYERITE => 8.6875, self::MEXALLON => 55.25, self::ISOGEN => 46.875],
        );

        $triggeredCount = $this->service->checkAlerts();

        $this->assertSame(3, $triggeredCount);
        $this->assertSame([
            'sell' => [self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN],
            'buy' => [self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN],
        ], $this->requestedJitaTypeIds);
        $this->assertSame(AlertStatus::Active, $isogenAboveOnBuyJustUnder->getStatus());
        $this->assertSame(AlertStatus::Active, $tritaniumBelowOnSell->getStatus());
        $this->assertNull($isogenAboveOnBuyJustUnder->getTriggeredAt());
        $this->assertNull($tritaniumBelowOnSell->getTriggeredAt());
        $this->assertSame([
            [self::USER_ID, self::TRITANIUM, 'above', 4.375, 'jita_sell', 4.5],
            [self::USER_ID, self::PYERITE, 'below', 9.125, 'jita_buy', 8.6875],
            [self::USER_ID, self::MEXALLON, 'below', 61.5, 'jita_sell', 61.5],
        ], $this->triggeredAlerts($alerts));
    }

    public function testJitaAlertWithoutJitaPriceIsSkipped(): void
    {
        $user = $this->createUser(self::USER_ID, null);
        $pyeriteAboveOnSellWithoutSellPrice = $this->createAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Above, 9.375, AlertPriceSource::JitaSell);
        $mexallonBelowOnBuyWithoutBuyPrice = $this->createAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Below, 62.25, AlertPriceSource::JitaBuy);
        $this->givenActiveAlertsAndJitaPrices(
            [$pyeriteAboveOnSellWithoutSellPrice, $mexallonBelowOnBuyWithoutBuyPrice],
            [self::PYERITE => null, self::MEXALLON => 60.125],
            [self::PYERITE => 9.8125, self::MEXALLON => null],
        );

        $triggeredCount = $this->service->checkAlerts();

        $this->assertSame(0, $triggeredCount);
        $this->assertSame(AlertStatus::Active, $pyeriteAboveOnSellWithoutSellPrice->getStatus());
        $this->assertSame(AlertStatus::Active, $mexallonBelowOnBuyWithoutBuyPrice->getStatus());
        $this->assertSame([], $this->triggeredAlerts([$pyeriteAboveOnSellWithoutSellPrice, $mexallonBelowOnBuyWithoutBuyPrice]));
    }

    public function testTriggeredAlertIsMarkedTriggeredAndLogged(): void
    {
        $user = $this->createUser(self::USER_ID, null);
        $alert = $this->createAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);
        (new \ReflectionProperty(MarketPriceAlert::class, 'id'))->setValue($alert, Uuid::fromString(self::ALERT_ID));
        $this->givenActiveAlertsAndJitaPrices([$alert], [self::PYERITE => 10.25], [self::PYERITE => 8.6875]);
        $checkStartedAt = time();

        $triggeredCount = $this->service->checkAlerts();

        $checkEndedAt = time();
        $this->assertSame(1, $triggeredCount);
        $this->assertSame(AlertStatus::Triggered, $alert->getStatus());
        $triggeredAt = $alert->getTriggeredAt();
        $this->assertNotNull($triggeredAt);
        $this->assertGreaterThanOrEqual($checkStartedAt, $triggeredAt->getTimestamp());
        $this->assertLessThanOrEqual($checkEndedAt, $triggeredAt->getTimestamp());
        $this->assertSame([[
            LogLevel::INFO,
            'Market alert triggered',
            [
                'alertId' => self::ALERT_ID,
                'typeId' => self::PYERITE,
                'typeName' => 'Pyerite',
                'direction' => 'below',
                'threshold' => 9.125,
                'currentPrice' => 8.6875,
            ],
        ]], $this->logger->cleanLogs());
    }

    #[Group('market-audit-2')]
    public function testTriggeredAlertCurrentlyOnlyPublishesARawMercureAlertAndNoNotificationMarketAudit2(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->once())->method('flush');
        $service = $this->createService($entityManager);
        $user = $this->createUser(self::USER_ID, null);
        $alert = $this->createAlert($user, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::JitaBuy);
        (new \ReflectionProperty(MarketPriceAlert::class, 'id'))->setValue($alert, Uuid::fromString(self::ALERT_ID));
        $this->givenActiveAlertsAndJitaPrices([$alert], [self::PYERITE => 10.25], [self::PYERITE => 8.6875]);
        $checkStartedAt = time();

        $service->checkAlerts();

        $checkEndedAt = time();
        $this->assertCount(1, $this->publishedUpdates);
        $this->assertSame(['/user/' . self::USER_ID . '/alerts/market-price'], $this->publishedUpdates[0]->getTopics());
        $this->assertTrue($this->publishedUpdates[0]->isPrivate());
        $payload = json_decode($this->publishedUpdates[0]->getData(), true, 512, JSON_THROW_ON_ERROR);
        $publishedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $payload['timestamp']);
        $this->assertNotFalse($publishedAt);
        $this->assertGreaterThanOrEqual($checkStartedAt, $publishedAt->getTimestamp());
        $this->assertLessThanOrEqual($checkEndedAt, $publishedAt->getTimestamp());
        unset($payload['timestamp']);
        $this->assertSame([
            'alertType' => 'market-price',
            'data' => [
                'alertId' => self::ALERT_ID,
                'typeId' => self::PYERITE,
                'typeName' => 'Pyerite',
                'direction' => 'below',
                'threshold' => 9.125,
                'currentPrice' => 8.6875,
                'priceSource' => 'jita_buy',
            ],
        ], $payload);
    }

    public function testStructureAlertsAreEvaluatedOnTheStructurePricesOfTheirOwnUser(): void
    {
        $this->givenStructurePrices(self::STRUCTURE_AT_CREATION, [
            self::MEXALLON => ['sell' => 63.25, 'buy' => 58.125],
            self::ISOGEN => ['sell' => 49.75, 'buy' => 44.5],
        ]);
        $this->givenStructurePrices(self::STRUCTURE_PREFERRED_NOW, [
            self::MEXALLON => ['sell' => 57.75, 'buy' => 52.375],
            self::ISOGEN => ['sell' => 51.0, 'buy' => 45.625],
        ]);
        $user = $this->createUser(self::USER_ID, self::STRUCTURE_AT_CREATION);
        $otherUser = $this->createUser(self::OTHER_USER_ID, self::STRUCTURE_PREFERRED_NOW);
        $mexallonBelowOnStructureSell = $this->createAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Below, 60.0, AlertPriceSource::StructureSell);
        $otherMexallonBelowOnStructureSell = $this->createAlert($otherUser, self::MEXALLON, 'Mexallon', AlertDirection::Below, 60.0, AlertPriceSource::StructureSell);
        $isogenAboveOnStructureBuyAtThreshold = $this->createAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 44.5, AlertPriceSource::StructureBuy);
        $otherIsogenAboveOnStructureBuy = $this->createAlert($otherUser, self::ISOGEN, 'Isogen', AlertDirection::Above, 46.0, AlertPriceSource::StructureBuy);
        $alerts = [$mexallonBelowOnStructureSell, $otherMexallonBelowOnStructureSell, $isogenAboveOnStructureBuyAtThreshold, $otherIsogenAboveOnStructureBuy];
        $this->givenActiveAlertsAndJitaPrices(
            $alerts,
            [self::MEXALLON => 59.5, self::ISOGEN => 53.25],
            [self::MEXALLON => 54.875, self::ISOGEN => 48.0],
        );

        $triggeredCount = $this->service->checkAlerts();

        $this->assertSame(2, $triggeredCount);
        $this->assertSame(AlertStatus::Active, $mexallonBelowOnStructureSell->getStatus());
        $this->assertSame(AlertStatus::Active, $otherIsogenAboveOnStructureBuy->getStatus());
        $this->assertSame([
            [self::OTHER_USER_ID, self::MEXALLON, 'below', 60.0, 'structure_sell', 57.75],
            [self::USER_ID, self::ISOGEN, 'above', 44.5, 'structure_buy', 44.5],
        ], $this->triggeredAlerts($alerts));
    }

    #[Group('market-audit-11')]
    public function testStructureAlertCurrentlyFollowsTheUserPreferredStructureOfTheMomentMarketAudit11(): void
    {
        $this->givenStructurePrices(self::STRUCTURE_AT_CREATION, [self::MEXALLON => ['sell' => 63.25, 'buy' => 58.125]]);
        $this->givenStructurePrices(self::STRUCTURE_PREFERRED_NOW, [self::MEXALLON => ['sell' => 57.75, 'buy' => 52.375]]);
        $user = $this->createUser(self::USER_ID, self::STRUCTURE_AT_CREATION);
        $alert = $this->createAlert($user, self::MEXALLON, 'Mexallon', AlertDirection::Below, 60.0, AlertPriceSource::StructureSell);
        $this->givenActiveAlertsAndJitaPrices([$alert], [self::MEXALLON => 59.5], [self::MEXALLON => 54.875]);

        $triggeredWhilePreferringTheCreationStructure = $this->service->checkAlerts();
        $user->setPreferredMarketStructureId(self::STRUCTURE_PREFERRED_NOW);
        $triggeredAfterChangingThePreferredStructure = $this->service->checkAlerts();

        $this->assertSame(0, $triggeredWhilePreferringTheCreationStructure);
        $this->assertSame(1, $triggeredAfterChangingThePreferredStructure);
        $this->assertSame([
            [self::USER_ID, self::MEXALLON, 'below', 60.0, 'structure_sell', 57.75],
        ], $this->triggeredAlerts([$alert]));
    }

    #[Group('market-audit-11')]
    public function testStructureAlertsWithoutStructurePriceCurrentlyStayActiveWithoutAnyVisibleStateMarketAudit11(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $entityManager->expects($this->never())->method('persist');
        $service = $this->createService($entityManager);
        $this->givenStructurePrices(self::STRUCTURE_AT_CREATION, [self::PYERITE => ['sell' => 9.875, 'buy' => 8.3125]]);
        $userWithoutCachedStructure = $this->createUser(self::USER_ID, self::STRUCTURE_WITHOUT_CACHE);
        $userWithoutPreferredStructure = $this->createUser(self::OTHER_USER_ID, null);
        $userWithoutId = $this->createUser(null, self::STRUCTURE_AT_CREATION);
        $isogenAlertOnStructureWithoutCache = $this->createAlert($userWithoutCachedStructure, self::ISOGEN, 'Isogen', AlertDirection::Above, 46.0, AlertPriceSource::StructureBuy);
        $mexallonAlertWithoutPreferredStructure = $this->createAlert($userWithoutPreferredStructure, self::MEXALLON, 'Mexallon', AlertDirection::Below, 60.0, AlertPriceSource::StructureSell);
        $pyeriteAlertOfUserWithoutId = $this->createAlert($userWithoutId, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::StructureBuy);
        $alerts = [$isogenAlertOnStructureWithoutCache, $mexallonAlertWithoutPreferredStructure, $pyeriteAlertOfUserWithoutId];
        $this->givenActiveAlertsAndJitaPrices(
            $alerts,
            [self::ISOGEN => 53.25, self::MEXALLON => 59.5, self::PYERITE => 10.25],
            [self::ISOGEN => 48.0, self::MEXALLON => 54.875, self::PYERITE => 8.6875],
        );

        $firstCheck = $service->checkAlerts();
        $secondCheck = $service->checkAlerts();

        $this->assertSame(0, $firstCheck);
        $this->assertSame(0, $secondCheck);
        foreach ($alerts as $alert) {
            $this->assertSame(AlertStatus::Active, $alert->getStatus());
            $this->assertNull($alert->getTriggeredAt());
        }
        $this->assertSame([], $this->publishedUpdates);
        $this->assertSame([], $this->logger->cleanLogs());
    }

    public function testUnpricedAlertsAndUsersWithoutIdentifierDoNotStopTheEvaluationOfTheFollowingAlerts(): void
    {
        $this->givenStructurePrices(self::STRUCTURE_AT_CREATION, [self::PYERITE => ['sell' => 9.875, 'buy' => 8.3125]]);
        $this->givenStructurePrices(self::STRUCTURE_PREFERRED_NOW, [self::ISOGEN => ['sell' => 51.0, 'buy' => 45.625]]);
        $userWithoutId = $this->createUser(null, self::STRUCTURE_AT_CREATION);
        $userWithoutPreferredStructure = $this->createUser(self::OTHER_USER_ID, null);
        $user = $this->createUser(self::USER_ID, self::STRUCTURE_PREFERRED_NOW);
        $structureAlertOfUserWithoutId = $this->createAlert($userWithoutId, self::PYERITE, 'Pyerite', AlertDirection::Below, 9.125, AlertPriceSource::StructureBuy);
        $structureAlertWithoutPreferredStructure = $this->createAlert($userWithoutPreferredStructure, self::MEXALLON, 'Mexallon', AlertDirection::Below, 60.0, AlertPriceSource::StructureSell);
        $jitaAlertOfUserWithoutId = $this->createAlert($userWithoutId, self::TRITANIUM, 'Tritanium', AlertDirection::Above, 4.375, AlertPriceSource::JitaSell);
        $structureAlert = $this->createAlert($user, self::ISOGEN, 'Isogen', AlertDirection::Above, 45.5, AlertPriceSource::StructureBuy);
        $this->givenActiveAlertsAndJitaPrices(
            [$structureAlertOfUserWithoutId, $structureAlertWithoutPreferredStructure, $jitaAlertOfUserWithoutId, $structureAlert],
            [self::PYERITE => 10.25, self::MEXALLON => 59.5, self::TRITANIUM => 4.5, self::ISOGEN => 53.25],
            [self::PYERITE => 8.6875, self::MEXALLON => 54.875, self::TRITANIUM => 3.9375, self::ISOGEN => 48.0],
        );

        $triggeredCount = $this->service->checkAlerts();

        $this->assertSame(2, $triggeredCount);
        $this->assertSame([
            [null, self::TRITANIUM, 'above', 4.375, 'jita_sell', 4.5],
            [self::USER_ID, self::ISOGEN, 'above', 45.5, 'structure_buy', 45.625],
        ], $this->triggeredAlerts([$jitaAlertOfUserWithoutId, $structureAlert]));
    }

    public function testNoActiveAlertRequestsNoPriceAndTriggersNothing(): void
    {
        $this->givenActiveAlertsAndJitaPrices([], [self::TRITANIUM => 4.5], [self::TRITANIUM => 3.9375]);

        $this->assertSame(0, $this->service->checkAlerts());
        $this->assertSame([], $this->requestedJitaTypeIds);
        $this->assertSame([], $this->triggeredAlerts([]));
    }

    private function createService(EntityManagerInterface $entityManager): MarketAlertService
    {
        return new MarketAlertService(
            $this->alertRepository,
            $this->jitaMarketService,
            $this->structureMarketService,
            $entityManager,
            $this->logger,
            $this->mercurePublisher,
        );
    }

    private function triggeredAlerts(array $alerts): array
    {
        $triggeredLogContexts = array_values(array_map(
            static fn (array $log): array => $log[2],
            array_filter($this->logger->cleanLogs(), static fn (array $log): bool => $log[1] === 'Market alert triggered'),
        ));
        $triggeredAlerts = array_values(array_filter(
            $alerts,
            static fn (MarketPriceAlert $alert): bool => $alert->getStatus() === AlertStatus::Triggered && $alert->getTriggeredAt() !== null,
        ));
        $this->assertCount(count($triggeredAlerts), $triggeredLogContexts);

        return array_map(
            static fn (MarketPriceAlert $alert, array $logContext): array => [
                $alert->getUser()->getId()?->toRfc4122(),
                $logContext['typeId'],
                $logContext['direction'],
                $logContext['threshold'],
                $alert->getPriceSource()->value,
                $logContext['currentPrice'],
            ],
            $triggeredAlerts,
            $triggeredLogContexts,
        );
    }

    private function createUser(?string $userId, ?int $preferredStructureId): User
    {
        $user = new User();
        if ($userId !== null) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));
        }
        $user->setPreferredMarketStructureId($preferredStructureId);

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
        return (new MarketPriceAlert())
            ->setUser($user)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setDirection($direction)
            ->setThreshold($threshold)
            ->setPriceSource($priceSource);
    }

    private function givenActiveAlertsAndJitaPrices(array $alerts, array $sellPrices, array $buyPrices): void
    {
        $this->alertRepository->method('findActiveAlerts')->willReturn($alerts);
        $this->givenJitaPrices($sellPrices, $buyPrices);
    }

    private function givenJitaPrices(array $sellPrices, array $buyPrices): void
    {
        $this->jitaMarketService->method('getPricesWithFallback')->willReturnCallback(function (array $typeIds) use ($sellPrices): array {
            $this->requestedJitaTypeIds['sell'] = $typeIds;

            return $sellPrices;
        });
        $this->jitaMarketService->method('getBuyPricesWithFallback')->willReturnCallback(function (array $typeIds) use ($buyPrices): array {
            $this->requestedJitaTypeIds['buy'] = $typeIds;

            return $buyPrices;
        });
    }

    private function givenStructurePrices(int $structureId, array $pricesByType): void
    {
        $this->structureCache->save($this->structureCache->getItem('structure_market_' . $structureId)->set($pricesByType));
    }
}
