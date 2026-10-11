<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sync;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Entity\UserLedgerSettings;
use App\Repository\MiningEntryRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\MiningSyncService;
use App\Service\TypeNameResolver;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class MiningSyncPriceHistoryTest extends IntegrationTestCase
{
    private const VELDSPAR = 1230;
    private const SCORDITE = 1228;
    private const KERNITE = 20;
    private const PREVIOUS_MINING_SYNC_AT = '2026-10-01 08:15:00+00:00';

    #[Group('mining-audit-P3-2')]
    public function testNewJitaPriceCurrentlyRewritesTheAlreadyPricedHistoryOfTheTypeMiningAuditP32(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->addEntry($user, '2026-09-20', self::VELDSPAR, 'Veldspar', 5_000, 14.5);
        $this->addEntry($user, '2026-10-10', self::VELDSPAR, 'Veldspar', 3_271, null);
        $this->addEntry($user, '2026-10-10', self::SCORDITE, 'Scordite', 1_844, null);
        $this->addEntry($user, '2026-09-30', self::KERNITE, 'Kernite', 912, 51.5);
        $this->addEntry($otherUser, '2026-09-25', self::VELDSPAR, 'Veldspar', 2_633, 13.0);
        $this->flushAndClear();
        $userId = $user->getId();
        $otherUserId = $otherUser->getId();

        $marketService = $this->createMock(MarketService::class);
        $marketService->expects($this->once())->method('getJitaPrices')->willReturnCallback(
            static function (array $typeIds): array {
                sort($typeIds);
                self::assertSame([self::SCORDITE, self::VELDSPAR], $typeIds);

                return [self::VELDSPAR => 17.0, self::SCORDITE => 12.0];
            }
        );

        $before = time();

        $results = $this->miningSyncService($marketService)->syncAll($this->em->getRepository(User::class)->find($userId));
        $this->em->clear();

        $this->assertSame(['imported' => 0, 'updated' => 0, 'pricesUpdated' => 3, 'errors' => []], array_intersect_key($results, ['imported' => 0, 'updated' => 0, 'pricesUpdated' => 0, 'errors' => []]));
        $lastMiningSyncAt = $this->storedSettings($userId)?->getLastMiningSyncAt()?->getTimestamp();
        $this->assertNotNull($lastMiningSyncAt);
        $this->assertGreaterThanOrEqual($before, $lastMiningSyncAt);
        $this->assertLessThanOrEqual(time(), $lastMiningSyncAt);
        $this->assertSame([
            ['2026-09-20', self::VELDSPAR, 5_000, 17.0, 85_000.0],
            ['2026-09-30', self::KERNITE, 912, 51.5, 46_968.0],
            ['2026-10-10', self::SCORDITE, 1_844, 12.0, 22_128.0],
            ['2026-10-10', self::VELDSPAR, 3_271, 17.0, 55_607.0],
        ], $this->pricedRows($userId));
        $this->assertSame([
            ['2026-09-25', self::VELDSPAR, 2_633, 13.0, 34_229.0],
        ], $this->pricedRows($otherUserId));
    }

    #[Group('mining-proposal-fractional-price-bigint')]
    public function testFractionalJitaPriceCurrentlyAbortsThePriceUpdateWithAGlobalError(): void
    {
        $user = $this->createUser();
        $this->addEntry($user, '2026-10-10', self::SCORDITE, 'Scordite', 1_844, null);
        $settings = (new UserLedgerSettings())->setUser($user)->setLastMiningSyncAt(new \DateTimeImmutable(self::PREVIOUS_MINING_SYNC_AT));
        $this->em->persist($settings);
        $this->flushAndClear();
        $userId = $user->getId();
        $managedSettings = $this->storedSettings($userId);

        $marketService = $this->createStub(MarketService::class);
        $marketService->method('getJitaPrices')->willReturn([self::SCORDITE => 11.75]);

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $results = $this->miningSyncService($marketService)->syncAll($this->em->getRepository(User::class)->find($userId));
        $lastMiningSyncAtAfterSync = $managedSettings?->getLastMiningSyncAt()?->format('Y-m-d H:i:sP');
        $connection->rollBack();
        $this->em->clear();

        $this->assertSame(['imported' => 0, 'updated' => 0, 'pricesUpdated' => 0], array_intersect_key($results, ['imported' => 0, 'updated' => 0, 'pricesUpdated' => 0]));
        $this->assertCount(1, $results['errors']);
        $this->assertStringStartsWith(
            'Global: An exception occurred while executing a query: SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type bigint: "11.75"',
            $results['errors'][0],
        );
        $this->assertSame(self::PREVIOUS_MINING_SYNC_AT, $lastMiningSyncAtAfterSync);
        $this->assertSame(self::PREVIOUS_MINING_SYNC_AT, $this->storedSettings($userId)?->getLastMiningSyncAt()?->format('Y-m-d H:i:sP'));
        $this->assertSame([
            ['2026-10-10', self::SCORDITE, 1_844, null, null],
        ], $this->pricedRows($userId));
    }

    private function storedSettings(mixed $userId): ?UserLedgerSettings
    {
        return $this->em->getRepository(UserLedgerSettings::class)->findOneBy(['user' => $userId]);
    }

    private function miningSyncService(MarketService $marketService): MiningSyncService
    {
        $container = self::getContainer();

        return new MiningSyncService(
            $this->createStub(EsiClient::class),
            $this->createStub(TokenManager::class),
            $marketService,
            $container->get(MiningEntryRepository::class),
            $container->get(UserLedgerSettingsRepository::class),
            $this->createStub(TypeNameResolver::class),
            $container->get(MapSolarSystemRepository::class),
            $this->em,
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    private function addEntry(User $user, string $date, int $typeId, string $typeName, int $quantity, ?float $unitPrice): void
    {
        $entry = (new MiningEntry())
            ->setUser($user)
            ->setCharacterId(93000002)
            ->setCharacterName('Ilka Thorne')
            ->setDate(new \DateTimeImmutable($date))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setSolarSystemId(30002813)
            ->setSolarSystemName('Tama')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice);
        $this->em->persist($entry);
    }

    private function pricedRows(mixed $userId): array
    {
        $entries = $this->em->getRepository(MiningEntry::class)->findBy(['user' => $userId], ['date' => 'ASC', 'typeId' => 'ASC']);

        return array_map(static fn (MiningEntry $entry): array => [
            $entry->getDate()->format('Y-m-d'),
            $entry->getTypeId(),
            $entry->getQuantity(),
            $entry->getUnitPrice(),
            $entry->getTotalValue(),
        ], $entries);
    }
}
