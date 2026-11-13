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
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(AdminStatsProvider::class)]
class AdminStatsProviderSchedulerHealthTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;

    public function testEachHealthEntryExposesWhetherItCanBeTriggeredManually(): void
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([
            'schedulerHealth' => [
                ['type' => 'cost-indices', 'expected_interval' => 7200, 'on_demand' => false, 'triggerable' => true, 'runs' => [], 'success_rate_24h' => null],
                ['type' => 'legacy', 'expected_interval' => 60, 'on_demand' => false, 'triggerable' => false, 'runs' => [], 'success_rate_24h' => null],
            ],
        ]);

        $resource = $this->provider($adminService)->provide(new Get());

        $this->assertTrue($resource->schedulerHealth[0]->triggerable);
        $this->assertFalse($resource->schedulerHealth[1]->triggerable);
    }

    public function testEachHealthEntryExposesItsRecentRunsAndItsSuccessRate(): void
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([
            'schedulerHealth' => [
                [
                    'type' => 'industry',
                    'expected_interval' => 1800,
                    'on_demand' => false,
                    'triggerable' => true,
                    'success_rate_24h' => 92,
                    'runs' => [
                        ['started_at' => '2026-11-12T08:00:00+00:00', 'completed_at' => '2026-11-12T08:00:42+00:00', 'status' => 'ok', 'message' => '3 jobs', 'stuck' => false],
                        ['started_at' => '2026-11-12T07:30:00+00:00', 'completed_at' => null, 'status' => 'error', 'message' => 'Stuck: still running after 90 min without completion', 'stuck' => true],
                    ],
                ],
                ['type' => 'pve', 'expected_interval' => 3600, 'on_demand' => false, 'triggerable' => false, 'runs' => [], 'success_rate_24h' => null],
            ],
        ]);

        $resource = $this->provider($adminService)->provide(new Get());

        $industry = $resource->schedulerHealth[0];
        $this->assertSame(92, $industry->successRate24h);
        $this->assertCount(2, $industry->runs);
        $this->assertSame('2026-11-12T08:00:00+00:00', $industry->runs[0]->startedAt);
        $this->assertSame('2026-11-12T08:00:42+00:00', $industry->runs[0]->completedAt);
        $this->assertSame('ok', $industry->runs[0]->status);
        $this->assertSame('3 jobs', $industry->runs[0]->message);
        $this->assertFalse($industry->runs[0]->stuck);
        $this->assertNull($industry->runs[1]->completedAt);
        $this->assertTrue($industry->runs[1]->stuck);
        $this->assertNull($resource->schedulerHealth[1]->successRate24h);
        $this->assertSame([], $resource->schedulerHealth[1]->runs);
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
