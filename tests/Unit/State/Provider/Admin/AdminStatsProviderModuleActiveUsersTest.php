<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\SdeBuildStateRepository;
use App\Security\AdminCharacterLister;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\CharacterRepairLister;
use App\Service\ESI\EsiErrorBudget;
use App\Service\Mercure\MercureCookieSizeMonitor;
use App\Service\Sde\SdeBuildStatusResolver;
use App\State\Provider\Admin\AdminStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AdminStatsProvider::class)]
#[Group('issue-650')]
class AdminStatsProviderModuleActiveUsersTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;

    public function testEachModuleRowKeepsItsCountsAndAnUnknownCountStaysNull(): void
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([
            'moduleActiveUsers' => [
                ['module' => 'industry', 'activeLast7Days' => 4, 'activeLast30Days' => 9],
                ['module' => 'contracts', 'activeLast7Days' => null, 'activeLast30Days' => null],
            ],
        ]);

        $resource = $this->provider($adminService)->provide(new Get());

        self::assertCount(2, $resource->moduleActiveUsers);
        self::assertSame(['industry', 4, 9], [
            $resource->moduleActiveUsers[0]->module,
            $resource->moduleActiveUsers[0]->activeLast7Days,
            $resource->moduleActiveUsers[0]->activeLast30Days,
        ]);
        self::assertSame(['contracts', null, null], [
            $resource->moduleActiveUsers[1]->module,
            $resource->moduleActiveUsers[1]->activeLast7Days,
            $resource->moduleActiveUsers[1]->activeLast30Days,
        ]);
    }

    private function provider(AdminService $adminService): AdminStatsProvider
    {
        $character = (new Character())
            ->setEveCharacterId(self::ADMIN_EVE_CHARACTER_ID)
            ->setName('Admin Pilot');

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AdminStatsProvider($security, $adminService, new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID), new EsiErrorBudget(new ArrayAdapter()), $this->createStub(MercureCookieSizeMonitor::class), $this->createStub(CharacterRepairLister::class), $this->createStub(AdminCharacterLister::class), $this->createStub(SdeBuildStateRepository::class), new SdeBuildStatusResolver());
    }
}
