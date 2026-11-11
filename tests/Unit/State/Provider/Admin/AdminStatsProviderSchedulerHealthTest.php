<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\State\Provider\Admin\AdminStatsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(AdminStatsProvider::class)]
class AdminStatsProviderSchedulerHealthTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;

    public function testEachHealthEntryExposesWhetherItCanBeTriggeredManually(): void
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([
            'schedulerHealth' => [
                ['type' => 'cost-indices', 'expected_interval' => 7200, 'on_demand' => false, 'triggerable' => true],
                ['type' => 'legacy', 'expected_interval' => 60, 'on_demand' => false, 'triggerable' => false],
            ],
        ]);

        $resource = $this->provider($adminService)->provide(new Get());

        $this->assertTrue($resource->schedulerHealth[0]->triggerable);
        $this->assertFalse($resource->schedulerHealth[1]->triggerable);
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

        return new AdminStatsProvider($security, $adminService, new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID));
    }
}
