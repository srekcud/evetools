<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Entity\UserNotificationPreference;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\ESI\PlanetaryService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Sync\PlanetarySyncService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetarySyncService::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-115')]
final class PlanetarySyncServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int PLANET_ID = 40009077;
    private const string PLANET_NAME = 'Planet I';
    private const string PILOT_NAME = 'Test Pilot';
    private const int EXTRACTOR_CONTROL_UNIT_TYPE_ID = 2848;
    private const string EXTRACTOR_CONTROL_UNIT_NAME = 'Barren Extractor Control Unit';
    private const int AQUEOUS_LIQUIDS_TYPE_ID = 2268;
    private const string AQUEOUS_LIQUIDS_NAME = 'Aqueous Liquids';

    private array $publishedUpdates = [];

    private array $dispatchedNotifications = [];

    private int $dispatchAttempts = 0;

    private bool $dispatcherFails = false;

    private ?int $planetaryThresholdMinutes = null;

    private MockClock $clock;

    private ?PlanetaryColony $syncedColony = null;

    private array $callLog = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock();
    }

    public function testPinOfTypeMissingFromSdeIsStoredWithoutTypeNameWhileKnownPinKeepsItsSdeName(): void
    {
        $this->syncColonyWithPins([
            self::extractorPin(1_017_000_001, self::AQUEOUS_LIQUIDS_TYPE_ID, '+3 days'),
            ['pin_id' => 1_017_000_002, 'type_id' => self::ROBOTICS_TYPE_ID, 'latitude' => 1.25, 'longitude' => 2.5],
        ]);

        self::assertSame(
            [
                [self::EXTRACTOR_CONTROL_UNIT_TYPE_ID, self::EXTRACTOR_CONTROL_UNIT_NAME],
                [self::ROBOTICS_TYPE_ID, null],
            ],
            array_map(
                static fn (PlanetaryPin $pin): array => [$pin->getTypeId(), $pin->getTypeName()],
                $this->syncedColony->getPins()->toArray(),
            ),
        );
    }

    public function testExpiredExtractorOfKnownProductKeepsItsSdeNameInNotificationAndAlert(): void
    {
        $this->syncColonyWithPins([self::extractorPin(1_017_000_003, self::AQUEOUS_LIQUIDS_TYPE_ID, '-1 hour')]);

        self::assertSame(
            [[
                'level' => Notification::LEVEL_ERROR,
                'title' => 'Extractor expired',
                'message' => 'Aqueous Liquids extractor on Planet I has expired (Test Pilot)',
                'data' => [
                    'colonyId' => null,
                    'planetName' => self::PLANET_NAME,
                    'productName' => self::AQUEOUS_LIQUIDS_NAME,
                    'characterName' => self::PILOT_NAME,
                ],
            ]],
            $this->dispatchedNotifications,
        );
        self::assertSame([self::AQUEOUS_LIQUIDS_NAME], $this->planetaryExpiryAlertProductNames());
    }

    public function testExpiredExtractorOfProductMissingFromSdeIsLabelledInNotificationTextWithoutProductName(): void
    {
        $this->syncColonyWithPins([self::extractorPin(1_017_000_004, self::ROBOTICS_TYPE_ID, '-1 hour')]);

        self::assertSame(
            [[
                'level' => Notification::LEVEL_ERROR,
                'title' => 'Extractor expired',
                'message' => 'type 9848 (name missing from the SDE) extractor on Planet I has expired (Test Pilot)',
                'data' => [
                    'colonyId' => null,
                    'planetName' => self::PLANET_NAME,
                    'productName' => null,
                    'characterName' => self::PILOT_NAME,
                ],
            ]],
            $this->dispatchedNotifications,
        );
        self::assertSame([null], $this->planetaryExpiryAlertProductNames());
    }

    public function testExtractorExpiringSoonWithProductMissingFromSdeIsLabelledInNotificationTextWithoutProductName(): void
    {
        $this->syncColonyWithPins([self::extractorPin(1_017_000_005, self::ROBOTICS_TYPE_ID, '+1830 seconds')]);

        self::assertSame(
            [[
                'level' => Notification::LEVEL_WARNING,
                'title' => 'Extractor expiring soon',
                'message' => 'type 9848 (name missing from the SDE) extractor on Planet I expires in 30 min (Test Pilot)',
                'data' => [
                    'colonyId' => null,
                    'planetName' => self::PLANET_NAME,
                    'productName' => null,
                    'minutesRemaining' => 30,
                    'characterName' => self::PILOT_NAME,
                ],
            ]],
            $this->dispatchedNotifications,
        );
        self::assertSame([null], $this->planetaryExpiryAlertProductNames());
    }

    #[Group('issue-537')]
    public function testExtractorExpiringWithinTheUserThresholdIsNotifiedAndAlerted(): void
    {
        $this->planetaryThresholdMinutes = 360;

        $this->syncColonyWithPins([self::extractorPin(1_017_000_010, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+300 minutes'))]);

        self::assertSame(
            [[
                'level' => Notification::LEVEL_WARNING,
                'title' => 'Extractor expiring soon',
                'message' => 'Aqueous Liquids extractor on Planet I expires in 300 min (Test Pilot)',
                'data' => [
                    'colonyId' => null,
                    'planetName' => self::PLANET_NAME,
                    'productName' => self::AQUEOUS_LIQUIDS_NAME,
                    'minutesRemaining' => 300,
                    'characterName' => self::PILOT_NAME,
                ],
            ]],
            $this->dispatchedNotifications,
        );
        self::assertSame([self::AQUEOUS_LIQUIDS_NAME], $this->planetaryExpiryAlertProductNames());
    }

    #[Group('issue-537')]
    public function testExtractorBeyondTheUserThresholdIsNeitherNotifiedNorAlerted(): void
    {
        $this->planetaryThresholdMinutes = 360;

        $this->syncColonyWithPins([self::extractorPin(1_017_000_011, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+420 minutes'))]);

        self::assertSame([], $this->dispatchedNotifications);
        self::assertSame([], $this->planetaryExpiryAlertProductNames());
    }

    #[Group('issue-537')]
    public function testWithoutPlanetaryPreferenceTheThresholdIsOneDay(): void
    {
        $this->planetaryThresholdMinutes = null;

        $this->syncColonyWithPins([
            self::extractorPin(1_017_000_012, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+23 hours')),
            self::extractorPin(1_017_000_013, self::ROBOTICS_TYPE_ID, $this->in('+25 hours')),
        ]);

        self::assertSame(
            [[Notification::LEVEL_WARNING, 'Aqueous Liquids extractor on Planet I expires in 1380 min (Test Pilot)']],
            array_map(static fn (array $notification): array => [$notification['level'], $notification['message']], $this->dispatchedNotifications),
        );
        self::assertSame([self::AQUEOUS_LIQUIDS_NAME], $this->planetaryExpiryAlertProductNames());
    }

    #[Group('issue-537')]
    public function testExtractorNotifiedAtWarningIsNotNotifiedAgainAtTheFollowingSyncsWhileMercureAlertsKeepComing(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;
        $expiry = $this->in('+300 minutes');

        $this->syncColonyWithPins([self::extractorPin(1_017_000_014, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);
        $this->clock->sleep(3600);
        $this->syncColonyWithPins([self::extractorPin(1_017_000_014, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);
        $this->clock->sleep(3600);
        $this->syncColonyWithPins([self::extractorPin(1_017_000_014, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);

        self::assertSame(
            [[Notification::LEVEL_WARNING, 'Aqueous Liquids extractor on Planet I expires in 300 min (Test Pilot)']],
            array_map(static fn (array $notification): array => [$notification['level'], $notification['message']], $this->dispatchedNotifications),
        );
        self::assertCount(3, $this->planetaryExpiryAlertProductNames());
    }

    #[Group('issue-537')]
    public function testExtractorIsNotifiedOncePerLevelWarningThenCriticalThenExpired(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = null;
        $expiry = $this->in('+300 minutes');
        $sync = fn () => $this->syncColonyWithPins([self::extractorPin(1_017_000_015, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);

        $sync();
        $this->clock->modify('2026-11-10 16:40:00 UTC');
        $sync();
        $this->clock->modify('2026-11-10 16:50:00 UTC');
        $sync();
        $this->clock->modify('2026-11-10 17:01:00 UTC');
        $sync();
        $this->clock->modify('2026-11-10 19:00:00 UTC');
        $sync();

        self::assertSame(
            [
                [Notification::LEVEL_WARNING, 'Aqueous Liquids extractor on Planet I expires in 300 min (Test Pilot)'],
                [Notification::LEVEL_CRITICAL, 'Aqueous Liquids extractor on Planet I expires in 20 min (Test Pilot)'],
                [Notification::LEVEL_ERROR, 'Aqueous Liquids extractor on Planet I has expired (Test Pilot)'],
            ],
            array_map(static fn (array $notification): array => [$notification['level'], $notification['message']], $this->dispatchedNotifications),
        );
    }

    #[Group('issue-537')]
    public function testNewExtractionCycleWithAChangedExpiryTimeResetsTheNotificationMemory(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;

        $this->syncColonyWithPins([self::extractorPin(1_017_000_016, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+300 minutes'))]);
        $this->clock->sleep(3600);
        $this->syncColonyWithPins([self::extractorPin(1_017_000_016, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+300 minutes'))]);

        self::assertSame(
            [
                [Notification::LEVEL_WARNING, 'Aqueous Liquids extractor on Planet I expires in 300 min (Test Pilot)'],
                [Notification::LEVEL_WARNING, 'Aqueous Liquids extractor on Planet I expires in 300 min (Test Pilot)'],
            ],
            array_map(static fn (array $notification): array => [$notification['level'], $notification['message']], $this->dispatchedNotifications),
        );
    }

    #[Group('issue-537')]
    public function testEachExtractorOfAColonyIsNotifiedOnceIndependently(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;
        $pins = [
            self::extractorPin(1_017_000_017, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+300 minutes')),
            self::extractorPin(1_017_000_018, self::ROBOTICS_TYPE_ID, $this->in('+200 minutes')),
        ];

        $this->syncColonyWithPins($pins);
        $this->clock->sleep(1800);
        $this->syncColonyWithPins($pins);

        self::assertSame(
            [
                'Aqueous Liquids extractor on Planet I expires in 300 min (Test Pilot)',
                'type 9848 (name missing from the SDE) extractor on Planet I expires in 200 min (Test Pilot)',
            ],
            array_column($this->dispatchedNotifications, 'message'),
        );
    }

    #[Group('issue-537')]
    public function testNotificationFailureIsNotReplayedAtTheNextSync(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;
        $expiry = $this->in('+300 minutes');
        $this->dispatcherFails = true;

        try {
            $this->syncColonyWithPins([self::extractorPin(1_017_000_019, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);
            self::fail('The dispatcher failure must reach the caller');
        } catch (\RuntimeException $exception) {
            self::assertSame('notification storage unavailable', $exception->getMessage());
        }
        $this->dispatcherFails = false;
        $this->clock->sleep(1800);
        $this->syncColonyWithPins([self::extractorPin(1_017_000_019, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);

        self::assertSame(1, $this->dispatchAttempts);
        self::assertSame([], $this->dispatchedNotifications);
    }

    #[Group('issue-537')]
    public function testNotifiedLevelIsRememberedAndFlushedBeforeTheNotificationIsDispatched(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;

        $this->syncColonyWithPins([self::extractorPin(1_017_000_020, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in('+300 minutes'))]);

        $dispatchPosition = array_search('dispatch:warning', $this->callLog, true);
        self::assertIsInt($dispatchPosition);
        self::assertSame('flush:warning', $this->callLog[$dispatchPosition - 1]);
    }

    #[Group('issue-537')]
    public function testExtractorThirtyOneMinutesBeforeExpiryIsNotifiedAtWarningLevel(): void
    {
        $this->assertLevelForMinutesBeforeExpiry(31, Notification::LEVEL_WARNING);
    }

    #[Group('issue-537')]
    public function testExtractorThirtyMinutesBeforeExpiryIsNotifiedAtWarningLevel(): void
    {
        $this->assertLevelForMinutesBeforeExpiry(30, Notification::LEVEL_WARNING);
    }

    #[Group('issue-537')]
    public function testExtractorTwentyNineMinutesBeforeExpiryIsNotifiedAtCriticalLevel(): void
    {
        $this->assertLevelForMinutesBeforeExpiry(29, Notification::LEVEL_CRITICAL);
    }

    #[Group('issue-537')]
    public function testFirstSeenExtractorAlreadyCriticalIsNotifiedOnceAtCriticalWithoutPriorWarning(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;
        $expiry = $this->in('+10 minutes');

        $this->syncColonyWithPins([self::extractorPin(1_017_000_024, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);
        $this->clock->sleep(60);
        $this->syncColonyWithPins([self::extractorPin(1_017_000_024, self::AQUEOUS_LIQUIDS_TYPE_ID, $expiry)]);

        self::assertSame(
            [[Notification::LEVEL_CRITICAL, 'Aqueous Liquids extractor on Planet I expires in 10 min (Test Pilot)']],
            array_map(static fn (array $notification): array => [$notification['level'], $notification['message']], $this->dispatchedNotifications),
        );
    }

    private function assertLevelForMinutesBeforeExpiry(int $minutes, string $expectedLevel): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $this->planetaryThresholdMinutes = 360;

        $this->syncColonyWithPins([self::extractorPin(1_017_000_021, self::AQUEOUS_LIQUIDS_TYPE_ID, $this->in(sprintf('+%d minutes', $minutes)))]);

        self::assertSame(
            [[$expectedLevel, sprintf('Aqueous Liquids extractor on Planet I expires in %d min (Test Pilot)', $minutes)]],
            array_map(static fn (array $notification): array => [$notification['level'], $notification['message']], $this->dispatchedNotifications),
        );
    }

    private function syncColonyWithPins(array $pins): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString('0192a3b4-5c6d-7e8f-9a0b-1c2d3e4f5a6b'));
        $character = $this->createStub(Character::class);
        $character->method('getUser')->willReturn($user);
        $character->method('getName')->willReturn(self::PILOT_NAME);

        $planetaryService = $this->createStub(PlanetaryService::class);
        $planetaryService->method('fetchColonies')->willReturn([[
            'planet_id' => self::PLANET_ID,
            'planet_type' => 'barren',
            'solar_system_id' => 30002187,
            'upgrade_level' => 4,
            'num_pins' => count($pins),
            'last_update' => '2026-10-19T08:00:00Z',
        ]]);
        $planetaryService->method('fetchColonyDetail')->willReturn(['pins' => $pins, 'routes' => []]);
        $planetaryService->method('fetchPlanetInfo')->willReturn(['name' => self::PLANET_NAME]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof PlanetaryColony) {
                $this->syncedColony = $entity;
            }
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $rememberedLevels = [];
            foreach ($this->syncedColony?->getPins() ?? [] as $pin) {
                $rememberedLevels[] = $pin->getExpiryNotifiedLevel()?->value ?? 'none';
            }
            $this->callLog[] = 'flush:' . implode(',', $rememberedLevels);
        });

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByCharacterAndPlanet')->willReturnCallback(fn (): ?PlanetaryColony => $this->syncedColony);
        $colonyRepository->method('findByCharacter')->willReturnCallback(
            fn (): array => $this->syncedColony === null ? [] : [$this->syncedColony],
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });

        $notificationDispatcher = $this->createStub(NotificationDispatcher::class);
        $notificationDispatcher->method('dispatch')->willReturnCallback(
            function (User $recipient, string $category, string $level, string $title, string $message, ?array $data = null): void {
                $this->dispatchAttempts++;
                $this->callLog[] = 'dispatch:' . $level;
                if ($this->dispatcherFails) {
                    throw new \RuntimeException('notification storage unavailable');
                }
                $this->dispatchedNotifications[] = ['level' => $level, 'title' => $title, 'message' => $message, 'data' => $data];
            },
        );

        $sdeTypes = $this->sdeTypeRepositoryKnowing([
            self::EXTRACTOR_CONTROL_UNIT_TYPE_ID => self::EXTRACTOR_CONTROL_UNIT_NAME,
            self::AQUEOUS_LIQUIDS_TYPE_ID => self::AQUEOUS_LIQUIDS_NAME,
        ]);

        $preferenceRepository = $this->createStub(UserNotificationPreferenceRepository::class);
        $preferenceRepository->method('findByUserAndCategory')->willReturnCallback(
            fn (User $owner): ?UserNotificationPreference => $owner === $user && $this->planetaryThresholdMinutes !== null
                ? (new UserNotificationPreference())->setCategory(Notification::CATEGORY_PLANETARY)->setThresholdMinutes($this->planetaryThresholdMinutes)
                : null,
        );

        $service = new PlanetarySyncService(
            $planetaryService,
            $colonyRepository,
            $this->createStub(MapSolarSystemRepository::class),
            new TypeNameResolver($sdeTypes),
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            $notificationDispatcher,
            new NullLogger(),
            new PlanetaryExpiryThresholdResolver($preferenceRepository),
            $this->clock,
        );

        self::assertSame(1, $service->syncCharacterColonies($character));
    }

    private function in(string $modifier): string
    {
        return $this->clock->now()->modify($modifier)->format(\DateTimeInterface::ATOM);
    }

    private function planetaryExpiryAlertProductNames(): array
    {
        $alerts = array_filter(
            $this->publishedUpdates,
            static fn (Update $update): bool => str_ends_with($update->getTopics()[0], '/alerts/planetary-expiry'),
        );

        return array_values(array_map(
            static fn (Update $update): mixed => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR)['data']['productName'],
            $alerts,
        ));
    }

    private static function extractorPin(int $pinId, int $productTypeId, string $expiresIn): array
    {
        return [
            'pin_id' => $pinId,
            'type_id' => self::EXTRACTOR_CONTROL_UNIT_TYPE_ID,
            'latitude' => 0.75,
            'longitude' => 1.5,
            'expiry_time' => (new \DateTimeImmutable($expiresIn))->format(\DateTimeInterface::ATOM),
            'extractor_details' => [
                'product_type_id' => $productTypeId,
                'cycle_time' => 1800,
                'qty_per_cycle' => 6_420,
                'head_radius' => 0.0125,
                'heads' => [['head_id' => 0, 'latitude' => 0.76, 'longitude' => 1.51]],
            ],
        ];
    }
}
