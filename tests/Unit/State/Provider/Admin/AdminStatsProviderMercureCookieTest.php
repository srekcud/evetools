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
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\Service\Mercure\MercureCookieUsage;
use App\State\Provider\Admin\AdminStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AdminStatsProvider::class)]
class AdminStatsProviderMercureCookieTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;

    public function testStatsListTheUsersNearTheMercureCookieLimitAndCountThem(): void
    {
        $monitor = $this->monitorReporting([
            new MercureCookieUsage('0196a1c0-0000-7000-8000-000000000001', 'Mira Tolland', 3900, 36),
            new MercureCookieUsage('0196a1c0-0000-7000-8000-000000000002', null, 3300, 31),
        ]);

        $mercureCookie = $this->provider($monitor)->provide(new Get())->mercureCookie;

        $this->assertSame(4096, $mercureCookie->limitBytes);
        $this->assertSame(3276, $mercureCookie->warningThresholdBytes);
        $this->assertSame(2, $mercureCookie->nearLimitCount);
        $this->assertCount(2, $mercureCookie->users);
        $this->assertSame('0196a1c0-0000-7000-8000-000000000001', $mercureCookie->users[0]->userId);
        $this->assertSame('Mira Tolland', $mercureCookie->users[0]->mainCharacterName);
        $this->assertSame(3900, $mercureCookie->users[0]->cookieBytes);
        $this->assertSame(36, $mercureCookie->users[0]->topicCount);
        $this->assertNull($mercureCookie->users[1]->mainCharacterName);
    }

    public function testStatsReportNoUserWhenEveryCookieIsFarFromTheLimit(): void
    {
        $mercureCookie = $this->provider($this->monitorReporting([]))->provide(new Get())->mercureCookie;

        $this->assertSame(0, $mercureCookie->nearLimitCount);
        $this->assertSame([], $mercureCookie->users);
    }

    /**
     * @param list<MercureCookieUsage> $usages
     */
    private function monitorReporting(array $usages): MercureCookieSizeMonitor
    {
        $monitor = $this->createStub(MercureCookieSizeMonitor::class);
        $monitor->method('usersNearLimit')->willReturn($usages);
        $monitor->method('warningThresholdBytes')->willReturn(3276);

        return $monitor;
    }

    private function provider(MercureCookieSizeMonitor $monitor): AdminStatsProvider
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
            new EsiErrorBudget(new ArrayAdapter()),
            $monitor,
            $this->createStub(CharacterRepairLister::class),
            $this->createStub(AdminCharacterLister::class),
        );
    }
}
