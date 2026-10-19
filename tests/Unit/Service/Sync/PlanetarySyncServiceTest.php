<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\PlanetaryService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sync\PlanetarySyncService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    private ?PlanetaryColony $syncedColony = null;

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

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof PlanetaryColony) {
                $this->syncedColony = $entity;
            }
        });

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByCharacterAndPlanet')->willReturn(null);
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
                $this->dispatchedNotifications[] = ['level' => $level, 'title' => $title, 'message' => $message, 'data' => $data];
            },
        );

        $sdeTypes = $this->sdeTypeRepositoryKnowing([
            self::EXTRACTOR_CONTROL_UNIT_TYPE_ID => self::EXTRACTOR_CONTROL_UNIT_NAME,
            self::AQUEOUS_LIQUIDS_TYPE_ID => self::AQUEOUS_LIQUIDS_NAME,
        ]);

        $service = new PlanetarySyncService(
            $planetaryService,
            $colonyRepository,
            $this->createStub(MapSolarSystemRepository::class),
            new TypeNameResolver($sdeTypes),
            $entityManager,
            new MercurePublisherService($hub, new NullLogger()),
            $notificationDispatcher,
            new NullLogger(),
        );

        self::assertSame(1, $service->syncCharacterColonies($character));
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
