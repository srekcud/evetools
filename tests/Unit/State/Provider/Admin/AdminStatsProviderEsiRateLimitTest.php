<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminCharacterLister;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\CharacterRepairLister;
use App\Service\ESI\EsiErrorBudget;
use App\Service\ESI\EsiRateLimitBudget;
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\State\Provider\Admin\AdminStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AdminStatsProvider::class)]
#[Group('issue-822')]
class AdminStatsProviderEsiRateLimitTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const PILOT_SCOPE = '0198c4a0-1111-7000-8000-00000000000a';

    public function testStatsExposeTheLowestGroupBudgetsSortedByRatioOfTheLimit(): void
    {
        $clock = new MockClock('2026-11-12 10:00:00 UTC');
        $rateLimitBudget = new EsiRateLimitBudget(new ArrayAdapter(clock: $clock), clock: $clock);
        $rateLimitBudget->record('/characters/1/assets/', self::PILOT_SCOPE, 200, $this->headers('assets', '150/15m', '20'));
        $rateLimitBudget->record('/characters/1/assets/', self::PILOT_SCOPE, 200, $this->headers('assets', '150/15m', '5'));
        $rateLimitBudget->record('/markets/10000002/orders/', EsiRateLimitBudget::PUBLIC_SCOPE, 200, $this->headers('market', '600/1h', '400'));

        $resource = $this->provider(new EsiErrorBudget(new ArrayAdapter()), $rateLimitBudget)->provide(new Get());

        $this->assertCount(2, $resource->esiRateLimits);
        $assets = $resource->esiRateLimits[0];
        $this->assertSame('assets', $assets->group);
        $this->assertSame(5, $assets->remaining);
        $this->assertSame(150, $assets->limit);
        $this->assertSame(900, $assets->windowSeconds);
        $this->assertSame('2026-11-12T10:00:00+00:00', $assets->observedAt);
        $this->assertSame('critical', $assets->level);
        $market = $resource->esiRateLimits[1];
        $this->assertSame('market', $market->group);
        $this->assertSame(400, $market->remaining);
        $this->assertSame(600, $market->limit);
        $this->assertSame(3600, $market->windowSeconds);
        $this->assertSame('ok', $market->level);
    }

    public function testStatsKeepTheErrorBudgetUnchangedNextToTheGroupBudgets(): void
    {
        $errorBudget = new EsiErrorBudget(new ArrayAdapter(), clock: new MockClock('2026-11-12 10:00:00 UTC'));
        $errorBudget->observe(12, 45);
        $rateLimitBudget = new EsiRateLimitBudget(new ArrayAdapter());
        $rateLimitBudget->record('/characters/1/assets/', self::PILOT_SCOPE, 200, $this->headers('assets', '150/15m', '5'));

        $resource = $this->provider($errorBudget, $rateLimitBudget)->provide(new Get());

        $this->assertSame(12, $resource->esiErrorBudget->remain);
        $this->assertSame(45, $resource->esiErrorBudget->resetSeconds);
        $this->assertSame('low', $resource->esiErrorBudget->level);
        $this->assertCount(1, $resource->esiRateLimits);
    }

    public function testStatsExposeNoGroupBudgetBeforeAnyObservation(): void
    {
        $resource = $this->provider(new EsiErrorBudget(new ArrayAdapter()), new EsiRateLimitBudget(new ArrayAdapter()))->provide(new Get());

        $this->assertSame([], $resource->esiRateLimits);
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(string $group, string $limit, string $remaining): array
    {
        return [
            'x-ratelimit-group' => [$group],
            'x-ratelimit-limit' => [$limit],
            'x-ratelimit-remaining' => [$remaining],
        ];
    }

    private function provider(EsiErrorBudget $errorBudget, EsiRateLimitBudget $rateLimitBudget): AdminStatsProvider
    {
        $character = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Admin Pilot');

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([]);

        return new AdminStatsProvider(
            $security,
            $adminService,
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
            $errorBudget,
            $this->createStub(MercureCookieSizeMonitor::class),
            $this->createStub(CharacterRepairLister::class),
            $this->createStub(AdminCharacterLister::class),
            $rateLimitBudget,
        );
    }
}
