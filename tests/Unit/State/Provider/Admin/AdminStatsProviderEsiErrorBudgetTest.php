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
use Symfony\Component\Clock\MockClock;

#[CoversClass(AdminStatsProvider::class)]
class AdminStatsProviderEsiErrorBudgetTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;

    public function testStatsExposeTheSharedEsiErrorBudget(): void
    {
        $errorBudget = new EsiErrorBudget(new ArrayAdapter(), clock: new MockClock('2026-11-11 10:30:00 UTC'));
        $errorBudget->observe(12, 45);
        $errorBudget->recordLimitedResponse(420, '/characters/1/assets/');
        $errorBudget->recordLimitedResponse(429, '/characters/2/assets/');

        $resource = $this->provider($errorBudget)->provide(new Get());

        $budget = $resource->esiErrorBudget;
        $this->assertSame(12, $budget->remain);
        $this->assertSame(45, $budget->resetSeconds);
        $this->assertSame('low', $budget->level);
        $this->assertSame('2026-11-11T10:30:00+00:00', $budget->observedAt);
        $this->assertSame('2026-11-11T10:30:45+00:00', $budget->windowEndsAt);
        $this->assertSame(1, $budget->limited420);
        $this->assertSame(1, $budget->limited429);
        $this->assertCount(24, $budget->hourly);
        $this->assertSame('/characters/{id}/assets/', $budget->byFamily[0]->family);
        $this->assertSame(1, $budget->byFamily[0]->limited420);
        $this->assertSame(1, $budget->byFamily[0]->limited429);
    }

    public function testStatsExposeAnUnknownBudgetBeforeAnyObservation(): void
    {
        $resource = $this->provider(new EsiErrorBudget(new ArrayAdapter()))->provide(new Get());

        $this->assertNull($resource->esiErrorBudget->remain);
        $this->assertSame('unknown', $resource->esiErrorBudget->level);
    }

    private function provider(EsiErrorBudget $errorBudget): AdminStatsProvider
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
            $this->createStub(SdeBuildStateRepository::class),
            new SdeBuildStatusResolver(),
        );
    }
}
