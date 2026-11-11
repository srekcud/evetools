<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\AdminStatusEvaluator;
use App\Service\Admin\MessengerQueueService;
use App\State\Provider\Admin\AdminStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(AdminStatusProvider::class)]
class AdminStatusProviderTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const OTHER_EVE_CHARACTER_ID = 90000002;

    public function testStatusIsEvaluatedFromTheStatsAndTheQueueDepths(): void
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([
            'users' => ['invalid' => 2],
            'schedulerHealth' => [['type' => 'assets', 'health' => 'stale', 'status' => 'ok', 'on_demand' => false]],
        ]);

        $resource = $this->provider($adminService, self::ADMIN_EVE_CHARACTER_ID)->provide(new Get());

        $this->assertSame('down', $resource->level);
        $this->assertSame(
            ['scheduler_stale', 'async_queue_unreachable', 'failed_queue_unreachable', 'tokens_invalid'],
            array_map(static fn (object $rule): string => $rule->key, $resource->rules),
        );
    }

    public function testNonAdminIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->createStub(AdminService::class), self::OTHER_EVE_CHARACTER_ID)->provide(new Get());
    }

    private function provider(AdminService $adminService, int $userEveCharacterId): AdminStatusProvider
    {
        $mainCharacter = (new Character())->setEveCharacterId($userEveCharacterId)->setName('Pilot');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($mainCharacter);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AdminStatusProvider(
            $security,
            $adminService,
            new MessengerQueueService(new InMemoryTransport(), new InMemoryTransport()),
            new AdminStatusEvaluator(),
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
        );
    }
}
