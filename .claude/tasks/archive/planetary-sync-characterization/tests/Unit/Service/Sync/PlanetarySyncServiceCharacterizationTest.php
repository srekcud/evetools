<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\UserNotificationPreferenceRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PlanetaryService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\WebPushService;
use App\Service\Sync\PlanetarySyncService;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetarySyncService::class)]
final class PlanetarySyncServiceCharacterizationTest extends TestCase
{
    private const string USER_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f01';
    private const string CHARACTER_UUID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f02';
    private const string EXISTING_COLONY_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f11';
    private const string STALE_COLONY_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f12';
    private const string OTHER_CHARACTER_COLONY_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f13';
    private const int EVE_CHARACTER_ID = 2119876543;
    private const string CHARACTER_NAME = 'Selene Arkwright';

    private const string SYNC_TOPIC = '/user/' . self::USER_ID . '/sync/planetary';
    private const string EXPIRY_ALERT_TOPIC = '/user/' . self::USER_ID . '/alerts/planetary-expiry';
    private const string COLONIES_ENDPOINT = '/characters/2119876543/planets/';

    private const int PLANET_AMAMAKE_IV = 40161469;
    private const int PLANET_VARD_II = 40161476;
    private const int PLANET_RENS_VII = 40161483;
    private const int PLANET_OTHER_CHARACTER = 40161490;
    private const int SYSTEM_AMAMAKE = 30002537;
    private const int SYSTEM_VARD = 30002538;
    private const int SYSTEM_RENS = 30002510;

    private const int BARREN_EXTRACTOR = 2848;
    private const int BARREN_BASIC_FACILITY = 2473;
    private const int BARREN_ADVANCED_FACILITY = 2474;
    private const int BARREN_LAUNCHPAD = 2544;
    private const int BASE_METALS = 2267;
    private const int AQUEOUS_LIQUIDS = 2268;
    private const int NOBLE_METALS = 2270;
    private const int HEAVY_METALS = 2272;
    private const int TOXIC_METALS = 2306;
    private const int REACTIVE_METALS = 2398;

    private const array TYPE_NAMES = [
        self::BARREN_EXTRACTOR => 'Barren Extractor Control Unit',
        self::BARREN_BASIC_FACILITY => 'Barren Basic Industry Facility',
        self::BARREN_ADVANCED_FACILITY => 'Barren Advanced Industry Facility',
        self::BASE_METALS => 'Base Metals',
        self::AQUEOUS_LIQUIDS => 'Aqueous Liquids',
        self::NOBLE_METALS => 'Noble Metals',
        self::HEAVY_METALS => 'Heavy Metals',
        self::TOXIC_METALS => 'Toxic Metals',
        self::REACTIVE_METALS => 'Reactive Metals',
    ];

    private const array SOLAR_SYSTEM_NAMES = [
        self::SYSTEM_AMAMAKE => 'Amamake',
        self::SYSTEM_RENS => 'Rens',
    ];

    private EsiClient&Stub $esiClient;
    private EveToken&Stub $token;
    private User $user;
    private Character $character;
    private Character $otherCharacter;
    private PlanetarySyncService $service;

    private array $storedColonies = [];
    private array $removedColonies = [];
    private array $persistedColonies = [];
    private array $persistedNotifications = [];
    private array $publishedUpdates = [];
    private array $esiCalls = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->token = $this->createStub(EveToken::class);

        $this->user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->user, Uuid::fromString(self::USER_ID));
        $this->character = $this->character(self::EVE_CHARACTER_ID, self::CHARACTER_NAME, $this->token);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($this->character, Uuid::fromString(self::CHARACTER_UUID));
        $this->user->addCharacter($this->character);
        $this->otherCharacter = $this->character(2119876544, 'Doran Kesh', $this->createStub(EveToken::class));

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByCharacter')->willReturnCallback(
            fn (Character $character): array => array_values(array_filter(
                $this->storedColonies,
                static fn (PlanetaryColony $colony): bool => $colony->getCharacter() === $character,
            )),
        );
        $colonyRepository->method('findByCharacterAndPlanet')->willReturnCallback(
            function (Character $character, int $planetId): ?PlanetaryColony {
                foreach ($this->storedColonies as $colony) {
                    if ($colony->getCharacter() === $character && $colony->getPlanetId() === $planetId) {
                        return $colony;
                    }
                }

                return null;
            },
        );

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturnCallback(
            static function (int $solarSystemId): ?MapSolarSystem {
                if (!isset(self::SOLAR_SYSTEM_NAMES[$solarSystemId])) {
                    return null;
                }

                return (new MapSolarSystem())
                    ->setSolarSystemId($solarSystemId)
                    ->setSolarSystemName(self::SOLAR_SYSTEM_NAMES[$solarSystemId]);
            },
        );

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(
            fn (int $typeId): ?InvType => isset(self::TYPE_NAMES[$typeId]) ? $this->invType(self::TYPE_NAMES[$typeId]) : null,
        );
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(function (array $typeIds): array {
            $found = [];
            foreach ($typeIds as $typeId) {
                if (isset(self::TYPE_NAMES[$typeId])) {
                    $found[$typeId] = $this->invType(self::TYPE_NAMES[$typeId]);
                }
            }

            return $found;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof PlanetaryColony) {
                $this->persistedColonies[] = $entity;
                $this->storedColonies[] = $entity;

                return;
            }
            $this->assertInstanceOf(Notification::class, $entity);
            $this->persistedNotifications[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removedColonies[] = $entity;
            $this->storedColonies = array_values(array_filter(
                $this->storedColonies,
                static fn (PlanetaryColony $colony): bool => $colony !== $entity,
            ));
        });
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $transactional): mixed => $transactional($entityManager),
        );

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        $mercurePublisher = new MercurePublisherService($hub, new NullLogger());

        $this->service = new PlanetarySyncService(
            new PlanetaryService($this->esiClient),
            $colonyRepository,
            $solarSystemRepository,
            new TypeNameResolver($invTypeRepository),
            $entityManager,
            $mercurePublisher,
            new NotificationDispatcher(
                $entityManager,
                $this->createStub(UserNotificationPreferenceRepository::class),
                $mercurePublisher,
                $this->createStub(WebPushService::class),
                new NullLogger(),
            ),
            new NullLogger(),
        );
    }

    public function testNewColonyIsPersistedWithEsiFieldsSolarSystemNamePlanetNamePinsAndRoutes(): void
    {
        $this->respondWith([
            self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 4, '2026-10-10T18:42:07Z')],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => [
                'pins' => [
                    [
                        'pin_id' => 1020345678901,
                        'type_id' => self::BARREN_EXTRACTOR,
                        'latitude' => 1.0472,
                        'longitude' => 2.3911,
                        'install_time' => '2026-10-08T06:00:00Z',
                        'expiry_time' => '2099-04-17T09:30:00Z',
                        'last_cycle_start' => '2026-10-10T18:00:00Z',
                        'extractor_details' => [
                            'product_type_id' => self::BASE_METALS,
                            'cycle_time' => 1800,
                            'qty_per_cycle' => 7413,
                            'head_radius' => 0.0183,
                            'heads' => [
                                ['head_id' => 0, 'latitude' => 1.05, 'longitude' => 2.39],
                                ['head_id' => 1, 'latitude' => 1.06, 'longitude' => 2.40],
                                ['head_id' => 2, 'latitude' => 1.07, 'longitude' => 2.41],
                            ],
                        ],
                    ],
                    [
                        'pin_id' => 1020345678902,
                        'type_id' => self::BARREN_BASIC_FACILITY,
                        'latitude' => 1.0391,
                        'longitude' => 2.4012,
                        'last_cycle_start' => '2026-10-10T17:30:00Z',
                        'factory_details' => ['schematic_id' => 127],
                        'contents' => [['type_id' => self::BASE_METALS, 'amount' => 2850]],
                    ],
                    [
                        'pin_id' => 1020345678903,
                        'type_id' => self::BARREN_ADVANCED_FACILITY,
                        'latitude' => 1.0287,
                        'longitude' => 2.4155,
                        'schematic_id' => 72,
                        'factory_details' => ['schematic_id' => 9999],
                    ],
                    [
                        'pin_id' => 1020345678904,
                        'type_id' => self::BARREN_LAUNCHPAD,
                        'contents' => [],
                    ],
                ],
                'routes' => [
                    [
                        'route_id' => 51001,
                        'source_pin_id' => 1020345678901,
                        'destination_pin_id' => 1020345678902,
                        'content_type_id' => self::BASE_METALS,
                        'quantity' => 3000,
                        'waypoints' => [1020345678904],
                    ],
                    [
                        'route_id' => 51002,
                        'source_pin_id' => 1020345678902,
                        'destination_pin_id' => 1020345678903,
                        'content_type_id' => self::REACTIVE_METALS,
                        'quantity' => 20,
                        'waypoints' => [],
                    ],
                ],
            ],
            self::planetInfoEndpoint(self::PLANET_AMAMAKE_IV) => ['name' => 'Amamake IV', 'planet_id' => self::PLANET_AMAMAKE_IV, 'system_id' => self::SYSTEM_AMAMAKE, 'type_id' => 2016],
        ]);

        $syncStartedAt = new \DateTimeImmutable();
        $synced = $this->service->syncCharacterColonies($this->character);
        $syncEndedAt = new \DateTimeImmutable();

        $this->assertSame(1, $synced);
        $this->assertCount(1, $this->persistedColonies);
        $colony = $this->persistedColonies[0];
        $this->assertSame([
            'character' => $this->character,
            'planetId' => self::PLANET_AMAMAKE_IV,
            'planetType' => 'barren',
            'solarSystemId' => self::SYSTEM_AMAMAKE,
            'solarSystemName' => 'Amamake',
            'planetName' => 'Amamake IV',
            'upgradeLevel' => 4,
            'numPins' => 4,
            'lastUpdate' => '2026-10-10T18:42:07+00:00',
        ], self::colonyRow($colony));
        $this->assertGreaterThanOrEqual($syncStartedAt, $colony->getCachedAt());
        $this->assertLessThanOrEqual($syncEndedAt, $colony->getCachedAt());
        $this->assertSame([
            [
                'pinId' => 1020345678901,
                'typeId' => self::BARREN_EXTRACTOR,
                'typeName' => 'Barren Extractor Control Unit',
                'schematicId' => null,
                'latitude' => 1.0472,
                'longitude' => 2.3911,
                'installTime' => '2026-10-08T06:00:00+00:00',
                'expiryTime' => '2099-04-17T09:30:00+00:00',
                'lastCycleStart' => '2026-10-10T18:00:00+00:00',
                'extractorProductTypeId' => self::BASE_METALS,
                'extractorCycleTime' => 1800,
                'extractorQtyPerCycle' => 7413,
                'extractorHeadRadius' => 0.0183,
                'extractorNumHeads' => 3,
                'contents' => null,
            ],
            [
                'pinId' => 1020345678902,
                'typeId' => self::BARREN_BASIC_FACILITY,
                'typeName' => 'Barren Basic Industry Facility',
                'schematicId' => 127,
                'latitude' => 1.0391,
                'longitude' => 2.4012,
                'installTime' => null,
                'expiryTime' => null,
                'lastCycleStart' => '2026-10-10T17:30:00+00:00',
                'extractorProductTypeId' => null,
                'extractorCycleTime' => null,
                'extractorQtyPerCycle' => null,
                'extractorHeadRadius' => null,
                'extractorNumHeads' => null,
                'contents' => [['type_id' => self::BASE_METALS, 'amount' => 2850]],
            ],
            [
                'pinId' => 1020345678903,
                'typeId' => self::BARREN_ADVANCED_FACILITY,
                'typeName' => 'Barren Advanced Industry Facility',
                'schematicId' => 72,
                'latitude' => 1.0287,
                'longitude' => 2.4155,
                'installTime' => null,
                'expiryTime' => null,
                'lastCycleStart' => null,
                'extractorProductTypeId' => null,
                'extractorCycleTime' => null,
                'extractorQtyPerCycle' => null,
                'extractorHeadRadius' => null,
                'extractorNumHeads' => null,
                'contents' => null,
            ],
            [
                'pinId' => 1020345678904,
                'typeId' => self::BARREN_LAUNCHPAD,
                'typeName' => 'Type #2544',
                'schematicId' => null,
                'latitude' => null,
                'longitude' => null,
                'installTime' => null,
                'expiryTime' => null,
                'lastCycleStart' => null,
                'extractorProductTypeId' => null,
                'extractorCycleTime' => null,
                'extractorQtyPerCycle' => null,
                'extractorHeadRadius' => null,
                'extractorNumHeads' => null,
                'contents' => null,
            ],
        ], self::pinRows($colony));
        $this->assertSame([
            ['routeId' => 51001, 'sourcePinId' => 1020345678901, 'destinationPinId' => 1020345678902, 'contentTypeId' => self::BASE_METALS, 'quantity' => 3000.0, 'waypoints' => [1020345678904]],
            ['routeId' => 51002, 'sourcePinId' => 1020345678902, 'destinationPinId' => 1020345678903, 'contentTypeId' => self::REACTIVE_METALS, 'quantity' => 20.0, 'waypoints' => null],
        ], self::routeRows($colony));
        $this->assertSame([], $this->removedColonies);
        $this->assertSame([
            ['started', 0, 'Fetching planetary colonies...', null],
            ['in_progress', 20, '1 colonies found...', null],
            ['in_progress', 90, 'Colony 1/1 processed...', null],
            ['completed', 100, '1 colonies synced', ['total' => 1]],
        ], $this->syncEvents());
        $this->assertSame([], $this->persistedNotifications);
        $this->assertSame([
            [self::COLONIES_ENDPOINT, true],
            [self::detailEndpoint(self::PLANET_AMAMAKE_IV), true],
            [self::planetInfoEndpoint(self::PLANET_AMAMAKE_IV), false],
        ], $this->sortedEsiCalls());
    }

    public function testExistingColonyKeepsItsIdentityAndPlanetNameAndGetsItsPinsAndRoutesReplaced(): void
    {
        $colony = $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $colony->setSolarSystemName('Previous Name');
        $colony->addPin((new PlanetaryPin())->setPinId(1020300000001)->setTypeId(self::BARREN_EXTRACTOR)->setExtractorProductTypeId(self::HEAVY_METALS));
        $colony->addRoute((new PlanetaryRoute())->setRouteId(49001)->setSourcePinId(1020300000001)->setDestinationPinId(1020300000002)->setContentTypeId(self::HEAVY_METALS)->setQuantity(1500.0));
        $this->respondWith([
            self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 5, 1, '2026-10-11T07:05:33Z')],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => [
                'pins' => [[
                    'pin_id' => 1020345679911,
                    'type_id' => self::BARREN_EXTRACTOR,
                    'latitude' => 0.8127,
                    'longitude' => 5.1033,
                    'install_time' => '2026-10-11T07:00:00Z',
                    'expiry_time' => '2099-01-02T03:04:05Z',
                    'last_cycle_start' => '2026-10-11T07:00:00Z',
                    'extractor_details' => [
                        'product_type_id' => self::NOBLE_METALS,
                        'cycle_time' => 900,
                        'qty_per_cycle' => 2291,
                        'head_radius' => 0.0254,
                        'heads' => [['head_id' => 0, 'latitude' => 0.81, 'longitude' => 5.10]],
                    ],
                ]],
                'routes' => [],
            ],
        ]);

        $syncStartedAt = new \DateTimeImmutable();
        $this->assertSame(1, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([$colony], $this->storedColonies);
        $this->assertGreaterThanOrEqual($syncStartedAt, $colony->getCachedAt());
        $this->assertSame([], $this->persistedColonies);
        $this->assertSame(self::EXISTING_COLONY_ID, $colony->getId()?->toRfc4122());
        $this->assertSame([
            'character' => $this->character,
            'planetId' => self::PLANET_AMAMAKE_IV,
            'planetType' => 'barren',
            'solarSystemId' => self::SYSTEM_AMAMAKE,
            'solarSystemName' => 'Amamake',
            'planetName' => 'Amamake IV',
            'upgradeLevel' => 5,
            'numPins' => 1,
            'lastUpdate' => '2026-10-11T07:05:33+00:00',
        ], self::colonyRow($colony));
        $this->assertSame([[
            'pinId' => 1020345679911,
            'typeId' => self::BARREN_EXTRACTOR,
            'typeName' => 'Barren Extractor Control Unit',
            'schematicId' => null,
            'latitude' => 0.8127,
            'longitude' => 5.1033,
            'installTime' => '2026-10-11T07:00:00+00:00',
            'expiryTime' => '2099-01-02T03:04:05+00:00',
            'lastCycleStart' => '2026-10-11T07:00:00+00:00',
            'extractorProductTypeId' => self::NOBLE_METALS,
            'extractorCycleTime' => 900,
            'extractorQtyPerCycle' => 2291,
            'extractorHeadRadius' => 0.0254,
            'extractorNumHeads' => 1,
            'contents' => null,
        ]], self::pinRows($colony));
        $this->assertSame([], self::routeRows($colony));
        $this->assertSame([
            [self::COLONIES_ENDPOINT, true],
            [self::detailEndpoint(self::PLANET_AMAMAKE_IV), true],
        ], $this->sortedEsiCalls());
    }

    public function testColoniesAbsentFromEsiAreRemovedAndNewOnesCreatedWithProgressPerColony(): void
    {
        $existing = $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $stale = $this->storedColony(self::STALE_COLONY_ID, $this->character, self::PLANET_RENS_VII, 'Rens VII');
        $otherCharacterColony = $this->storedColony(self::OTHER_CHARACTER_COLONY_ID, $this->otherCharacter, self::PLANET_OTHER_CHARACTER, 'Hek III');
        $this->respondWith([
            self::COLONIES_ENDPOINT => [
                self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 3, 0, '2026-10-09T21:14:50Z'),
                self::colonyListEntry(self::PLANET_VARD_II, 'temperate', self::SYSTEM_VARD, 2, 0, '2026-10-07T11:02:19Z'),
            ],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => ['pins' => [], 'routes' => []],
            self::detailEndpoint(self::PLANET_VARD_II) => ['pins' => [], 'routes' => []],
            self::planetInfoEndpoint(self::PLANET_VARD_II) => ['name' => 'Vard II', 'planet_id' => self::PLANET_VARD_II, 'system_id' => self::SYSTEM_VARD, 'type_id' => 11],
        ]);

        $this->assertSame(2, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([$stale], $this->removedColonies);
        $this->assertCount(1, $this->persistedColonies);
        $this->assertSame([$existing, $otherCharacterColony, $this->persistedColonies[0]], $this->storedColonies);
        $this->assertSame([
            'character' => $this->character,
            'planetId' => self::PLANET_VARD_II,
            'planetType' => 'temperate',
            'solarSystemId' => self::SYSTEM_VARD,
            'solarSystemName' => null,
            'planetName' => 'Vard II',
            'upgradeLevel' => 2,
            'numPins' => 0,
            'lastUpdate' => '2026-10-07T11:02:19+00:00',
        ], self::colonyRow($this->persistedColonies[0]));
        $this->assertSame([], self::pinRows($this->persistedColonies[0]));
        $this->assertSame([], self::routeRows($this->persistedColonies[0]));
        $this->assertSame([
            ['started', 0, 'Fetching planetary colonies...', null],
            ['in_progress', 20, '2 colonies found...', null],
            ['in_progress', 55, 'Colony 1/2 processed...', null],
            ['in_progress', 90, 'Colony 2/2 processed...', null],
            ['completed', 100, '2 colonies synced', ['total' => 2]],
        ], $this->syncEvents());
    }

    public function testEmptyColonyListFromEsiRemovesEveryColonyOfTheCharacter(): void
    {
        $first = $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $second = $this->storedColony(self::STALE_COLONY_ID, $this->character, self::PLANET_RENS_VII, 'Rens VII');
        $otherCharacterColony = $this->storedColony(self::OTHER_CHARACTER_COLONY_ID, $this->otherCharacter, self::PLANET_OTHER_CHARACTER, 'Hek III');
        $this->respondWith([self::COLONIES_ENDPOINT => []]);

        $this->assertSame(0, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([$first, $second], $this->removedColonies);
        $this->assertSame([$otherCharacterColony], $this->storedColonies);
        $this->assertSame([
            ['started', 0, 'Fetching planetary colonies...', null],
            ['completed', 100, 'No planetary colonies', ['total' => 0]],
        ], $this->syncEvents());
        $this->assertSame([[self::COLONIES_ENDPOINT, true]], $this->sortedEsiCalls());
    }

    public function testEmptyColonyListWithoutStoredColonyRemovesNothing(): void
    {
        $this->respondWith([self::COLONIES_ENDPOINT => []]);

        $this->assertSame(0, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([], $this->removedColonies);
    }

    #[Group('pi-audit-8')]
    public function testCharacterWithoutTokenCurrentlyLosesAllItsColoniesWithoutEsiCallPiAudit8(): void
    {
        $this->character->setEveToken(null);
        $first = $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $second = $this->storedColony(self::STALE_COLONY_ID, $this->character, self::PLANET_RENS_VII, 'Rens VII');
        $this->respondWith([]);

        $this->assertSame(0, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([$first, $second], $this->removedColonies);
        $this->assertSame([], $this->storedColonies);
        $this->assertSame([], $this->esiCalls);
        $this->assertSame([
            ['started', 0, 'Fetching planetary colonies...', null],
            ['completed', 100, 'No planetary colonies', ['total' => 0]],
        ], $this->syncEvents());
    }

    public function testExtractorsExpiredOrExpiringWithinTwoHoursAreNotifiedWithLevelAndMinutesRemaining(): void
    {
        $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $this->respondWithExpiryScenario();

        $this->service->syncCharacterColonies($this->character);

        $this->assertSame([
            [
                'user' => $this->user,
                'category' => 'planetary',
                'level' => 'error',
                'title' => 'Extractor expired',
                'message' => 'Base Metals extractor on Amamake IV has expired (Selene Arkwright)',
                'data' => ['colonyId' => self::EXISTING_COLONY_ID, 'planetName' => 'Amamake IV', 'productName' => 'Base Metals', 'characterName' => self::CHARACTER_NAME],
                'route' => '/planetary',
            ],
            [
                'user' => $this->user,
                'category' => 'planetary',
                'level' => 'critical',
                'title' => 'Extractor expiring soon',
                'message' => 'Aqueous Liquids extractor on Amamake IV expires in 20 min (Selene Arkwright)',
                'data' => ['colonyId' => self::EXISTING_COLONY_ID, 'planetName' => 'Amamake IV', 'productName' => 'Aqueous Liquids', 'minutesRemaining' => 20, 'characterName' => self::CHARACTER_NAME],
                'route' => '/planetary',
            ],
            [
                'user' => $this->user,
                'category' => 'planetary',
                'level' => 'warning',
                'title' => 'Extractor expiring soon',
                'message' => 'Noble Metals extractor on Amamake IV expires in 30 min (Selene Arkwright)',
                'data' => ['colonyId' => self::EXISTING_COLONY_ID, 'planetName' => 'Amamake IV', 'productName' => 'Noble Metals', 'minutesRemaining' => 30, 'characterName' => self::CHARACTER_NAME],
                'route' => '/planetary',
            ],
            [
                'user' => $this->user,
                'category' => 'planetary',
                'level' => 'warning',
                'title' => 'Extractor expiring soon',
                'message' => 'Heavy Metals extractor on Amamake IV expires in 119 min (Selene Arkwright)',
                'data' => ['colonyId' => self::EXISTING_COLONY_ID, 'planetName' => 'Amamake IV', 'productName' => 'Heavy Metals', 'minutesRemaining' => 119, 'characterName' => self::CHARACTER_NAME],
                'route' => '/planetary',
            ],
        ], $this->notificationRows());
    }

    #[Group('pi-audit-7')]
    public function testExtractorWithoutExpiryCurrentlyRaisesNoAlertPiAudit7(): void
    {
        $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $this->respondWith([
            self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 2, '2026-10-10T18:42:07Z')],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => ['pins' => [
                ['pin_id' => 1020345670016, 'type_id' => self::BARREN_EXTRACTOR, 'extractor_details' => ['product_type_id' => self::REACTIVE_METALS, 'cycle_time' => 3600, 'qty_per_cycle' => 4129]],
                self::extractorPin(1020345670018, self::HEAVY_METALS, '2026-10-04T22:10:00Z'),
            ], 'routes' => []],
        ]);

        $this->service->syncCharacterColonies($this->character);

        $this->assertSame(
            ['Heavy Metals extractor on Amamake IV has expired (Selene Arkwright)'],
            array_column($this->notificationRows(), 'message'),
        );
        $this->assertSame(['Heavy Metals'], array_column($this->expiryAlerts(), 'productName'));
    }

    #[Group('pi-audit-15')]
    public function testColonyDetailWithoutPinsCurrentlyWipesStoredPinsPiAudit15(): void
    {
        $colony = $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $colony->addPin((new PlanetaryPin())->setPinId(1020300000004)->setTypeId(self::BARREN_EXTRACTOR)->setExtractorProductTypeId(self::NOBLE_METALS));
        $colony->addRoute((new PlanetaryRoute())->setRouteId(49002)->setSourcePinId(1020300000004)->setDestinationPinId(1020300000005)->setContentTypeId(self::NOBLE_METALS)->setQuantity(2750.0));
        $this->respondWith([
            self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 1, '2026-10-10T18:42:07Z')],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => [],
        ]);

        $this->assertSame(1, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([], self::pinRows($colony));
        $this->assertSame([], self::routeRows($colony));
    }

    #[Group('pi-audit-12')]
    public function testExpiryAlertsAreCurrentlyAlsoPublishedOnTheUnconsumedPlanetaryExpiryTopicPiAudit12(): void
    {
        $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $this->respondWithExpiryScenario();

        $this->service->syncCharacterColonies($this->character);

        $this->assertSame([
            ['level' => 'expired', 'planetName' => 'Amamake IV', 'planetType' => 'barren', 'productName' => 'Base Metals', 'characterName' => self::CHARACTER_NAME, 'colonyId' => self::EXISTING_COLONY_ID],
            ['level' => 'critical', 'minutesRemaining' => 20, 'planetName' => 'Amamake IV', 'planetType' => 'barren', 'productName' => 'Aqueous Liquids', 'characterName' => self::CHARACTER_NAME, 'colonyId' => self::EXISTING_COLONY_ID],
            ['level' => 'warning', 'minutesRemaining' => 30, 'planetName' => 'Amamake IV', 'planetType' => 'barren', 'productName' => 'Noble Metals', 'characterName' => self::CHARACTER_NAME, 'colonyId' => self::EXISTING_COLONY_ID],
            ['level' => 'warning', 'minutesRemaining' => 119, 'planetName' => 'Amamake IV', 'planetType' => 'barren', 'productName' => 'Heavy Metals', 'characterName' => self::CHARACTER_NAME, 'colonyId' => self::EXISTING_COLONY_ID],
        ], $this->expiryAlerts());
    }

    #[Group('pi-audit-3')]
    public function testExpiredExtractorIsCurrentlyNotifiedAgainOnEverySyncPiAudit3(): void
    {
        $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $this->respondWith([
            self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 1, '2026-10-10T18:42:07Z')],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => ['pins' => [self::extractorPin(1020345670001, self::TOXIC_METALS, '2026-10-01T03:15:00Z')], 'routes' => []],
        ]);
        $expectedNotification = [
            'user' => $this->user,
            'category' => 'planetary',
            'level' => 'error',
            'title' => 'Extractor expired',
            'message' => 'Toxic Metals extractor on Amamake IV has expired (Selene Arkwright)',
            'data' => ['colonyId' => self::EXISTING_COLONY_ID, 'planetName' => 'Amamake IV', 'productName' => 'Toxic Metals', 'characterName' => self::CHARACTER_NAME],
            'route' => '/planetary',
        ];

        $this->service->syncCharacterColonies($this->character);
        $this->service->syncCharacterColonies($this->character);

        $this->assertSame([$expectedNotification, $expectedNotification], $this->notificationRows());
    }

    #[Group('pi-audit-15')]
    public function testPlanetNameLookupFailureCurrentlyLeavesPlanetNameEmptyAndAlertsFallBackToSystemOrUnknownPiAudit15(): void
    {
        $this->respondWith([
            self::COLONIES_ENDPOINT => [
                self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 1, '2026-10-10T18:42:07Z'),
                self::colonyListEntry(self::PLANET_VARD_II, 'temperate', self::SYSTEM_VARD, 1, 1, '2026-10-06T04:51:12Z'),
            ],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => ['pins' => [self::extractorPin(1020345670002, self::REACTIVE_METALS, '2026-10-02T12:00:00Z')], 'routes' => []],
            self::detailEndpoint(self::PLANET_VARD_II) => ['pins' => [self::extractorPin(1020345670003, self::HEAVY_METALS, '2026-10-03T16:45:00Z')], 'routes' => []],
        ]);

        $this->assertSame(2, $this->service->syncCharacterColonies($this->character));

        $this->assertSame([null, null], array_map(
            static fn (PlanetaryColony $colony): ?string => $colony->getPlanetName(),
            $this->persistedColonies,
        ));
        $this->assertSame([
            'Reactive Metals extractor on Amamake has expired (Selene Arkwright)',
            'Heavy Metals extractor on Unknown has expired (Selene Arkwright)',
        ], array_column($this->notificationRows(), 'message'));
        $this->assertSame('completed', $this->syncEvents()[4][0]);
    }

    #[Group('pi-audit-15')]
    public function testMissingColonyFieldsCurrentlyDefaultToUnknownTypeAndZeroUpgradeAndPinsPiAudit15(): void
    {
        $this->respondWith([
            self::COLONIES_ENDPOINT => [['planet_id' => self::PLANET_AMAMAKE_IV, 'solar_system_id' => self::SYSTEM_AMAMAKE, 'last_update' => '2026-10-10T18:42:07Z']],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => ['pins' => [], 'routes' => []],
            self::planetInfoEndpoint(self::PLANET_AMAMAKE_IV) => ['name' => 'Amamake IV', 'planet_id' => self::PLANET_AMAMAKE_IV, 'system_id' => self::SYSTEM_AMAMAKE, 'type_id' => 2016],
        ]);

        $this->service->syncCharacterColonies($this->character);

        $this->assertSame(
            ['unknown', 0, 0],
            [$this->persistedColonies[0]->getPlanetType(), $this->persistedColonies[0]->getUpgradeLevel(), $this->persistedColonies[0]->getNumPins()],
        );
    }

    public function testCharacterWithoutUserSyncsColoniesWithoutPublishingOrNotifying(): void
    {
        $orphanCharacter = $this->character(2119876545, 'Ivo Marsh', $this->token);
        $this->respondWith([
            '/characters/2119876545/planets/' => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 1, '2026-10-10T18:42:07Z')],
            '/characters/2119876545/planets/' . self::PLANET_AMAMAKE_IV . '/' => ['pins' => [self::extractorPin(1020345670004, self::BASE_METALS, '2026-10-02T12:00:00Z')], 'routes' => []],
            self::planetInfoEndpoint(self::PLANET_AMAMAKE_IV) => ['name' => 'Amamake IV', 'planet_id' => self::PLANET_AMAMAKE_IV, 'system_id' => self::SYSTEM_AMAMAKE, 'type_id' => 2016],
        ]);

        $this->assertSame(1, $this->service->syncCharacterColonies($orphanCharacter));

        $this->assertCount(1, $this->persistedColonies);
        $this->assertSame([], $this->publishedUpdates);
        $this->assertSame([], $this->persistedNotifications);
    }

    public function testEsiFailureOnColonyDetailPublishesSyncErrorAndRethrows(): void
    {
        $failure = EsiApiException::fromResponse(502, 'Bad Gateway', self::detailEndpoint(self::PLANET_AMAMAKE_IV));
        $this->respondWith(
            [self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 1, '2026-10-10T18:42:07Z')]],
            [self::detailEndpoint(self::PLANET_AMAMAKE_IV) => $failure],
        );

        $caught = null;
        try {
            $this->service->syncCharacterColonies($this->character);
        } catch (EsiApiException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame([], $this->persistedColonies);
        $this->assertSame([
            ['started', 0, 'Fetching planetary colonies...', null],
            ['in_progress', 20, '1 colonies found...', null],
            ['error', null, 'Bad Gateway', null],
        ], $this->syncEvents());
    }

    public function testEsiFailureOnColonyListPublishesSyncErrorAndKeepsStoredColonies(): void
    {
        $stored = $this->storedColony(self::EXISTING_COLONY_ID, $this->character, self::PLANET_AMAMAKE_IV, 'Amamake IV');
        $failure = EsiApiException::fromResponse(503, 'Service Unavailable', self::COLONIES_ENDPOINT);
        $this->respondWith([], [self::COLONIES_ENDPOINT => $failure]);

        $caught = null;
        try {
            $this->service->syncCharacterColonies($this->character);
        } catch (EsiApiException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame([$stored], $this->storedColonies);
        $this->assertSame([], $this->removedColonies);
        $this->assertSame([
            ['started', 0, 'Fetching planetary colonies...', null],
            ['error', null, 'Service Unavailable', null],
        ], $this->syncEvents());
    }

    private function respondWithExpiryScenario(): void
    {
        $now = time();
        $this->respondWith([
            self::COLONIES_ENDPOINT => [self::colonyListEntry(self::PLANET_AMAMAKE_IV, 'barren', self::SYSTEM_AMAMAKE, 4, 6, '2026-10-10T18:42:07Z')],
            self::detailEndpoint(self::PLANET_AMAMAKE_IV) => ['pins' => [
                ['pin_id' => 1020345670017, 'type_id' => self::BARREN_BASIC_FACILITY, 'schematic_id' => 127, 'expiry_time' => '2026-10-01T03:15:00Z'],
                self::extractorPin(1020345670011, self::BASE_METALS, '2026-10-01T03:15:00Z'),
                self::extractorPin(1020345670012, self::AQUEOUS_LIQUIDS, self::utc($now + 20 * 60 + 30)),
                self::extractorPin(1020345670013, self::NOBLE_METALS, self::utc($now + 30 * 60 + 30)),
                self::extractorPin(1020345670014, self::HEAVY_METALS, self::utc($now + 119 * 60 + 30)),
                self::extractorPin(1020345670015, self::TOXIC_METALS, self::utc($now + 121 * 60)),
            ], 'routes' => []],
        ]);
    }

    private function respondWith(array $responsesByEndpoint, array $failuresByEndpoint = []): void
    {
        $this->esiClient->method('get')->willReturnCallback(
            function (string $endpoint, ?EveToken $token = null) use ($responsesByEndpoint, $failuresByEndpoint): array {
                $this->esiCalls[] = [$endpoint, $token === $this->token];
                if (isset($failuresByEndpoint[$endpoint])) {
                    throw $failuresByEndpoint[$endpoint];
                }
                if (isset($responsesByEndpoint[$endpoint])) {
                    return $responsesByEndpoint[$endpoint];
                }
                if (str_starts_with($endpoint, '/universe/planets/')) {
                    throw EsiApiException::fromResponse(504, 'Gateway Timeout', $endpoint);
                }
                throw new \LogicException("Unexpected ESI endpoint {$endpoint}");
            },
        );
    }

    private function sortedEsiCalls(): array
    {
        $calls = $this->esiCalls;
        sort($calls);

        return $calls;
    }

    private function character(int $eveCharacterId, string $name, ?EveToken $token): Character
    {
        return (new Character())
            ->setEveCharacterId($eveCharacterId)
            ->setName($name)
            ->setCorporationId(98000123)
            ->setCorporationName('Arkwright Extraction')
            ->setEveToken($token);
    }

    private function storedColony(string $colonyId, Character $character, int $planetId, string $planetName): PlanetaryColony
    {
        $colony = (new PlanetaryColony())
            ->setCharacter($character)
            ->setPlanetId($planetId)
            ->setPlanetType('barren')
            ->setSolarSystemId(self::SYSTEM_AMAMAKE)
            ->setPlanetName($planetName)
            ->setUpgradeLevel(1)
            ->setNumPins(0)
            ->setLastUpdate(new \DateTimeImmutable('2026-09-30T10:00:00Z'))
            ->setCachedAt(new \DateTimeImmutable('2026-09-30T10:05:00Z'));
        (new \ReflectionProperty(PlanetaryColony::class, 'id'))->setValue($colony, Uuid::fromString($colonyId));
        $this->storedColonies[] = $colony;

        return $colony;
    }

    private function invType(string $typeName): InvType
    {
        $type = $this->createStub(InvType::class);
        $type->method('getTypeName')->willReturn($typeName);

        return $type;
    }

    private function syncEvents(): array
    {
        $events = [];
        foreach ($this->publishedUpdates as $update) {
            if ($update->getTopics() === [self::SYNC_TOPIC]) {
                $payload = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                $events[] = [$payload['status'], $payload['progress'], $payload['message'], $payload['data']];
            }
        }

        return $events;
    }

    private function expiryAlerts(): array
    {
        $alerts = [];
        foreach ($this->publishedUpdates as $update) {
            if ($update->getTopics() === [self::EXPIRY_ALERT_TOPIC]) {
                $alerts[] = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR)['data'];
            }
        }

        return $alerts;
    }

    private function notificationRows(): array
    {
        return array_map(static fn (Notification $notification): array => [
            'user' => $notification->getUser(),
            'category' => $notification->getCategory(),
            'level' => $notification->getLevel(),
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'data' => $notification->getData(),
            'route' => $notification->getRoute(),
        ], $this->persistedNotifications);
    }

    private static function colonyRow(PlanetaryColony $colony): array
    {
        return [
            'character' => $colony->getCharacter(),
            'planetId' => $colony->getPlanetId(),
            'planetType' => $colony->getPlanetType(),
            'solarSystemId' => $colony->getSolarSystemId(),
            'solarSystemName' => $colony->getSolarSystemName(),
            'planetName' => $colony->getPlanetName(),
            'upgradeLevel' => $colony->getUpgradeLevel(),
            'numPins' => $colony->getNumPins(),
            'lastUpdate' => $colony->getLastUpdate()->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function pinRows(PlanetaryColony $colony): array
    {
        return array_values(array_map(static fn (PlanetaryPin $pin): array => [
            'pinId' => $pin->getPinId(),
            'typeId' => $pin->getTypeId(),
            'typeName' => $pin->getTypeName(),
            'schematicId' => $pin->getSchematicId(),
            'latitude' => $pin->getLatitude(),
            'longitude' => $pin->getLongitude(),
            'installTime' => $pin->getInstallTime()?->format(\DateTimeInterface::ATOM),
            'expiryTime' => $pin->getExpiryTime()?->format(\DateTimeInterface::ATOM),
            'lastCycleStart' => $pin->getLastCycleStart()?->format(\DateTimeInterface::ATOM),
            'extractorProductTypeId' => $pin->getExtractorProductTypeId(),
            'extractorCycleTime' => $pin->getExtractorCycleTime(),
            'extractorQtyPerCycle' => $pin->getExtractorQtyPerCycle(),
            'extractorHeadRadius' => $pin->getExtractorHeadRadius(),
            'extractorNumHeads' => $pin->getExtractorNumHeads(),
            'contents' => $pin->getContents(),
        ], $colony->getPins()->toArray()));
    }

    private static function routeRows(PlanetaryColony $colony): array
    {
        return array_values(array_map(static fn (PlanetaryRoute $route): array => [
            'routeId' => $route->getRouteId(),
            'sourcePinId' => $route->getSourcePinId(),
            'destinationPinId' => $route->getDestinationPinId(),
            'contentTypeId' => $route->getContentTypeId(),
            'quantity' => $route->getQuantity(),
            'waypoints' => $route->getWaypoints(),
        ], $colony->getRoutes()->toArray()));
    }

    private static function colonyListEntry(int $planetId, string $planetType, int $solarSystemId, int $upgradeLevel, int $numPins, string $lastUpdate): array
    {
        return [
            'planet_id' => $planetId,
            'planet_type' => $planetType,
            'solar_system_id' => $solarSystemId,
            'upgrade_level' => $upgradeLevel,
            'num_pins' => $numPins,
            'last_update' => $lastUpdate,
            'owner_id' => self::EVE_CHARACTER_ID,
        ];
    }

    private static function extractorPin(int $pinId, int $productTypeId, string $expiryTime): array
    {
        return [
            'pin_id' => $pinId,
            'type_id' => self::BARREN_EXTRACTOR,
            'expiry_time' => $expiryTime,
            'extractor_details' => ['product_type_id' => $productTypeId, 'cycle_time' => 1800, 'qty_per_cycle' => 6085],
        ];
    }

    private static function detailEndpoint(int $planetId): string
    {
        return self::COLONIES_ENDPOINT . $planetId . '/';
    }

    private static function planetInfoEndpoint(int $planetId): string
    {
        return '/universe/planets/' . $planetId . '/';
    }

    private static function utc(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
