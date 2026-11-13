<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Repository\CachedAssetRepository;
use App\Service\Sync\CorporationAssetsRefreshGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CorporationAssetsRefreshGate::class)]
#[Group('issue-865')]
final class CorporationAssetsRefreshGateTest extends TestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const string NOW = '2026-11-13 12:10:00';
    private const string LAST_SYNC = '2026-11-13 12:00:00';

    private MockClock $clock;
    private ArrayAdapter $cache;

    /** @var array<int, \DateTimeImmutable|null> */
    private array $lastSyncByCorporation = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->cache = new ArrayAdapter(clock: $this->clock);
        $this->lastSyncByCorporation = [self::CORPORATION_ID => new \DateTimeImmutable(self::LAST_SYNC)];
    }

    public function testCooldownMatchesTheEsiCacheDurationOfTheCorporationAssetsEndpoint(): void
    {
        self::assertSame(3600, CorporationAssetsRefreshGate::COOLDOWN_SECONDS);
    }

    public function testNextRefreshIsOneCooldownAfterTheLastSync(): void
    {
        $nextRefreshAt = $this->gate()->nextRefreshAt(self::CORPORATION_ID);

        self::assertEquals(new \DateTimeImmutable('2026-11-13 13:00:00'), $nextRefreshAt);
    }

    public function testRefreshIsStillBlockedOneSecondBeforeTheCooldownEnds(): void
    {
        $this->clock->modify('2026-11-13 12:59:59');

        self::assertNotNull($this->gate()->nextRefreshAt(self::CORPORATION_ID));
    }

    public function testRefreshIsAllowedExactlyWhenTheCooldownEnds(): void
    {
        $this->clock->modify('2026-11-13 13:00:00');

        self::assertNull($this->gate()->nextRefreshAt(self::CORPORATION_ID));
    }

    public function testRefreshIsAllowedWhenTheLastSyncIsOlderThanTheCooldown(): void
    {
        $this->clock->modify('2026-11-14 08:00:00');

        self::assertNull($this->gate()->nextRefreshAt(self::CORPORATION_ID));
    }

    public function testRefreshIsAllowedWhenTheCorporationHasNeverBeenSynced(): void
    {
        self::assertNull($this->gate()->nextRefreshAt(self::OTHER_CORPORATION_ID));
    }

    public function testLastSyncIsTheNewestCachedAtOfTheCorporationAssets(): void
    {
        self::assertEquals(new \DateTimeImmutable(self::LAST_SYNC), $this->gate()->lastSyncedAt(self::CORPORATION_ID));
        self::assertNull($this->gate()->lastSyncedAt(self::OTHER_CORPORATION_ID));
    }

    public function testFirstClaimWinsAndTheSecondOneIsRefused(): void
    {
        $gate = $this->gate();

        self::assertTrue($gate->claim(self::CORPORATION_ID));
        self::assertFalse($gate->claim(self::CORPORATION_ID));
    }

    public function testClaimsOfDifferentCorporationsAreIndependent(): void
    {
        $gate = $this->gate();

        self::assertTrue($gate->claim(self::CORPORATION_ID));
        self::assertTrue($gate->claim(self::OTHER_CORPORATION_ID));
    }

    public function testReleaseAllowsANewClaim(): void
    {
        $gate = $this->gate();
        $gate->claim(self::CORPORATION_ID);

        $gate->release(self::CORPORATION_ID);

        self::assertTrue($gate->claim(self::CORPORATION_ID));
    }

    public function testReleaseOfAnotherCorporationKeepsTheClaim(): void
    {
        $gate = $this->gate();
        $gate->claim(self::CORPORATION_ID);

        $gate->release(self::OTHER_CORPORATION_ID);

        self::assertFalse($gate->claim(self::CORPORATION_ID));
    }

    public function testReleaseWithoutClaimIsHarmless(): void
    {
        $gate = $this->gate();

        $gate->release(self::CORPORATION_ID);

        self::assertTrue($gate->claim(self::CORPORATION_ID));
    }

    public function testClaimIsStillHeldJustBeforeTheInFlightTtl(): void
    {
        $gate = $this->gate();
        $gate->claim(self::CORPORATION_ID);

        $this->clock->sleep(CorporationAssetsRefreshGate::IN_FLIGHT_TTL_SECONDS - 1);

        self::assertFalse($gate->claim(self::CORPORATION_ID));
    }

    public function testClaimExpiresByItselfAfterTheInFlightTtl(): void
    {
        $gate = $this->gate();
        $gate->claim(self::CORPORATION_ID);

        $this->clock->sleep(CorporationAssetsRefreshGate::IN_FLIGHT_TTL_SECONDS + 1);

        self::assertTrue($gate->claim(self::CORPORATION_ID));
    }

    private function gate(): CorporationAssetsRefreshGate
    {
        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('lastCachedAtOfCorporationAssets')->willReturnCallback(
            fn (int $corporationId): ?\DateTimeImmutable => $this->lastSyncByCorporation[$corporationId] ?? null,
        );

        return new CorporationAssetsRefreshGate($cachedAssetRepository, $this->cache, $this->clock);
    }
}
