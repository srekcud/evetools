<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Admin;

use ApiPlatform\Metadata\Get;
use App\Entity\Character;
use App\Entity\User;
use App\Security\AdminChecker;
use App\Service\Admin\AdminService;
use App\Service\Admin\AdminStatusEvaluator;
use App\Service\Admin\InFlightMessageRegistry;
use App\Service\Admin\MessengerQueueService;
use App\State\Provider\Admin\AdminStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(AdminStatusProvider::class)]
class AdminStatusProviderTest extends TestCase
{
    private const ADMIN_EVE_CHARACTER_ID = 90000001;
    private const OTHER_EVE_CHARACTER_ID = 90000002;
    private const START_TIME = 1_800_000_000;
    private const ELEVEN_HOURS_IN_SECONDS = 39600;

    public function testStatusIsEvaluatedFromTheStatsAndTheQueueDepths(): void
    {
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn([
            'users' => ['invalid' => 2],
            'schedulerHealth' => [['type' => 'assets', 'health' => 'stale', 'status' => 'ok', 'on_demand' => false]],
        ]);

        $resource = $this->provider($adminService, self::ADMIN_EVE_CHARACTER_ID, $this->registry())->provide(new Get());

        $this->assertSame('down', $resource->level);
        $this->assertSame(
            ['scheduler_stale', 'async_queue_unreachable', 'failed_queue_unreachable', 'tokens_invalid'],
            array_map(static fn (object $rule): string => $rule->key, $resource->rules),
        );
    }

    #[Group('issue-838')]
    public function testStatusReportsAMessageRunningSinceElevenHours(): void
    {
        $clock = new MockClock('@' . self::START_TIME);
        $registry = $this->registry($clock);
        $registry->start('on_demand', 'App\Message\SyncCharacterAssets');
        $clock->modify('@' . (self::START_TIME + self::ELEVEN_HOURS_IN_SECONDS));
        $adminService = $this->createStub(AdminService::class);
        $adminService->method('getStats')->willReturn(['users' => ['invalid' => 0], 'schedulerHealth' => []]);

        $resource = $this->provider($adminService, self::ADMIN_EVE_CHARACTER_ID, $registry)->provide(new Get());

        $rule = array_values(array_filter($resource->rules, static fn (object $rule): bool => $rule->key === 'message_unacked_too_long'))[0] ?? null;
        $this->assertNotNull($rule);
        $this->assertSame(self::ELEVEN_HOURS_IN_SECONDS, $rule->value);
        $this->assertSame(3600, $rule->threshold);
    }

    public function testNonAdminIsRefused(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->createStub(AdminService::class), self::OTHER_EVE_CHARACTER_ID, $this->registry())->provide(new Get());
    }

    private function provider(AdminService $adminService, int $userEveCharacterId, InFlightMessageRegistry $registry): AdminStatusProvider
    {
        $mainCharacter = (new Character())->setEveCharacterId($userEveCharacterId)->setName('Pilot');
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($mainCharacter);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AdminStatusProvider(
            $security,
            $adminService,
            new MessengerQueueService(new InMemoryTransport(), new InMemoryTransport(), new InMemoryTransport()),
            $registry,
            new AdminStatusEvaluator(),
            new AdminChecker((string) self::ADMIN_EVE_CHARACTER_ID),
        );
    }

    private function registry(?MockClock $clock = null): InFlightMessageRegistry
    {
        $clock ??= new MockClock('@' . self::START_TIME);

        return new InFlightMessageRegistry(new ArrayAdapter(clock: $clock), $clock);
    }
}
