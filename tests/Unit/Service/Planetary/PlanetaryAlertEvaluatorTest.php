<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Entity\Character;
use App\Entity\Notification;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Planetary\PlanetaryAlertEvaluator;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PlanetaryAlertEvaluator::class)]
#[Group('issue-1391')]
final class PlanetaryAlertEvaluatorTest extends TestCase
{
    private const NOW = '2026-11-26 12:00:00';
    private const DEFAULT_WINDOW_MINUTES = 1440;

    private const COLONY_ID = '0199a1f0-0000-7000-8000-000000001391';
    private const OTHER_COLONY_ID = '0199a1f0-0000-7000-8000-000000001392';
    private const PLANET_NAME = 'Jita IV';
    private const OTHER_PLANET_NAME = 'Perimeter II';
    private const CHARACTER_NAME = 'Ilsa Vance';

    private const LAUNCHPAD_PIN_ID = 101;
    private const OTHER_LAUNCHPAD_PIN_ID = 102;
    private const STORAGE_PIN_ID = 201;

    private const STORAGE_GROUP_ID = 1029;
    private const SPACEPORT_GROUP_ID = 1030;
    private const BASIC_COMMODITIES_GROUP_ID = 1042;
    private const STORAGE_FACILITY_TYPE_ID = 2541;
    private const LAUNCHPAD_TYPE_ID = 2544;
    private const BIOFUELS = 2396;
    private const PRECIOUS_METALS = 2399;
    private const SUSPENDED_PLASMOIDS = 2389;
    private const UNMEASURED_CARGO = 2392;

    private const STORAGE_CAPACITY_M3 = 12000.0;
    private const LAUNCHPAD_CAPACITY_M3 = 10000.0;
    private const BIOFUELS_VOLUME_M3 = 0.5;
    private const PRECIOUS_METALS_VOLUME_M3 = 0.25;
    private const SUSPENDED_PLASMOIDS_VOLUME_M3 = 0.5;

    private const EMPTY_AT_SOON = '2026-11-27T06:00:00+00:00';
    private const EMPTY_AT_ALREADY = '2026-11-26T08:30:00+00:00';
    private const EMPTY_AT_FAR = '2026-11-28T12:00:00+00:00';
    private const EMPTY_AT_WINDOW_EDGE = '2026-11-27T12:00:00+00:00';

    private User $user;
    private Character $character;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->character = (new Character())->setName(self::CHARACTER_NAME);
        $this->user->addCharacter($this->character);
    }

    public function testLaunchpadRunningEmptyWithinTheWindowRaisesAWarning(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour');

        $dispatched = $this->evaluate([$colony], [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_SOON)])]);

        self::assertCount(1, $dispatched);
        self::assertSame(Notification::CATEGORY_PLANETARY, $dispatched[0]['category']);
        self::assertSame(Notification::LEVEL_WARNING, $dispatched[0]['level']);
        self::assertSame('Launchpad running empty', $dispatched[0]['title']);
        self::assertSame('/planetary', $dispatched[0]['route']);
        self::assertSame($this->user, $dispatched[0]['user']);
        self::assertSame(
            [
                'characterName' => self::CHARACTER_NAME,
                'colonyId' => self::COLONY_ID,
                'emptyAt' => self::EMPTY_AT_SOON,
                'kind' => 'launchpad_empty',
                'planetName' => self::PLANET_NAME,
                'subject' => 'launchpad:'.self::COLONY_ID.':'.self::LAUNCHPAD_PIN_ID,
            ],
            $this->sorted($dispatched[0]['data']),
        );
    }

    public function testLaunchpadAlreadyEmptyRaisesACriticalAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-6 hours');

        $dispatched = $this->evaluate([$colony], [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_ALREADY)])]);

        self::assertCount(1, $dispatched);
        self::assertSame(Notification::LEVEL_CRITICAL, $dispatched[0]['level']);
        self::assertSame('launchpad_empty', $dispatched[0]['data']['kind']);
        self::assertSame(self::EMPTY_AT_ALREADY, $dispatched[0]['data']['emptyAt']);
    }

    public function testLaunchpadEmptyingExactlyAtTheEdgeOfTheWindowRaisesAWarning(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour');

        $dispatched = $this->evaluate([$colony], [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_WINDOW_EDGE)])]);

        self::assertCount(1, $dispatched);
        self::assertSame(Notification::LEVEL_WARNING, $dispatched[0]['level']);
    }

    public function testLaunchpadEmptyingBeyondTheWindowRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour');

        $dispatched = $this->evaluate([$colony], [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_FAR)])]);

        self::assertSame([], $dispatched);
    }

    public function testLaunchpadWhoseFeedsHaveNoDailyRateRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour');

        $dispatched = $this->evaluate([$colony], [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [
            $this->feed(self::BIOFUELS, 0.0, null),
            $this->feed(self::PRECIOUS_METALS, 0.0, null),
        ])]);

        self::assertSame([], $dispatched);
    }

    public function testLaunchpadAlertUsesTheClosestEmptyAtAmongItsFeeds(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour');

        $dispatched = $this->evaluate([$colony], [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [
            $this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_FAR),
            $this->feed(self::SUSPENDED_PLASMOIDS, 0.0, null),
            $this->feed(self::PRECIOUS_METALS, 30.0, self::EMPTY_AT_SOON),
        ])]);

        self::assertCount(1, $dispatched);
        self::assertSame(Notification::LEVEL_WARNING, $dispatched[0]['level']);
        self::assertSame(self::EMPTY_AT_SOON, $dispatched[0]['data']['emptyAt']);
    }

    public function testLaunchpadWindowIsReadFromTheUserThreshold(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-30 minutes');
        $emptyInFourHours = '2026-11-26T16:00:00+00:00';
        $rows = [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, $emptyInFourHours)])];

        self::assertSame([], $this->evaluate([$colony], $rows, windowMinutes: 180));
        self::assertCount(1, $this->evaluate([$colony], $rows, windowMinutes: 300));
    }

    public function testStorageFilledToExactlyNinetyFivePercentRaisesAWarning(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour', $this->storagePin([
            ['type_id' => self::SUSPENDED_PLASMOIDS, 'amount' => 20000],
            ['type_id' => self::PRECIOUS_METALS, 'amount' => 5600],
        ]));

        $dispatched = $this->evaluate([$colony]);

        self::assertCount(1, $dispatched);
        self::assertSame(Notification::CATEGORY_PLANETARY, $dispatched[0]['category']);
        self::assertSame(Notification::LEVEL_WARNING, $dispatched[0]['level']);
        self::assertSame('Storage almost full', $dispatched[0]['title']);
        self::assertSame('/planetary', $dispatched[0]['route']);
        self::assertSame(
            [
                'characterName' => self::CHARACTER_NAME,
                'colonyId' => self::COLONY_ID,
                'kind' => 'storage_full',
                'planetName' => self::PLANET_NAME,
                'subject' => 'storage:'.self::COLONY_ID.':'.self::STORAGE_PIN_ID,
            ],
            $this->sorted($dispatched[0]['data']),
        );
    }

    public function testStorageFilledToJustUnderNinetyFivePercentRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour', $this->storagePin([
            ['type_id' => self::SUSPENDED_PLASMOIDS, 'amount' => 20000],
            ['type_id' => self::PRECIOUS_METALS, 'amount' => 5594],
        ]));

        self::assertSame([], $this->evaluate([$colony]));
    }

    public function testStorageHoldingATypeWithUnknownVolumeRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour', $this->storagePin([
            ['type_id' => self::SUSPENDED_PLASMOIDS, 'amount' => 23000],
            ['type_id' => self::UNMEASURED_CARGO, 'amount' => 10],
        ]));

        self::assertSame([], $this->evaluate([$colony]));
    }

    public function testStorageWithUnknownCapacityRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour', $this->storagePin([
            ['type_id' => self::SUSPENDED_PLASMOIDS, 'amount' => 23000],
        ]));

        self::assertSame([], $this->evaluate([$colony], storageCapacityM3: null));
    }

    public function testStorageWithoutContentsRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour', $this->storagePin(null));

        self::assertSame([], $this->evaluate([$colony]));
    }

    public function testFullLaunchpadRaisesNoStorageAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-1 hour', $this->launchpadPin([
            ['type_id' => self::SUSPENDED_PLASMOIDS, 'amount' => 20000],
        ]));

        self::assertSame([], $this->evaluate([$colony]));
    }

    public function testColonySeenTwentyFiveHoursAgoRaisesAnInfoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-25 hours');

        $dispatched = $this->evaluate([$colony]);

        self::assertCount(1, $dispatched);
        self::assertSame(Notification::CATEGORY_PLANETARY, $dispatched[0]['category']);
        self::assertSame(Notification::LEVEL_INFO, $dispatched[0]['level']);
        self::assertSame('Colony data is stale', $dispatched[0]['title']);
        self::assertSame('/planetary', $dispatched[0]['route']);
        self::assertSame(
            [
                'characterName' => self::CHARACTER_NAME,
                'colonyId' => self::COLONY_ID,
                'kind' => 'data_stale',
                'planetName' => self::PLANET_NAME,
                'subject' => 'stale:'.self::COLONY_ID,
            ],
            $this->sorted($dispatched[0]['data']),
        );
    }

    public function testColonySeenTwentyThreeHoursAgoRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-23 hours');

        self::assertSame([], $this->evaluate([$colony]));
    }

    public function testColonySeenExactlyOneWindowAgoRaisesNoAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-24 hours');

        self::assertSame([], $this->evaluate([$colony]));
    }

    public function testStaleWindowIsReadFromTheUserThreshold(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-90 minutes');

        self::assertSame([], $this->evaluate([$colony], windowMinutes: 120));
        self::assertCount(1, $this->evaluate([$colony], windowMinutes: 60));
    }

    public function testColonyWithoutFactoryRowFromTheCalculatorIsStillCheckedForStaleData(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-25 hours');

        $dispatched = $this->evaluate([$colony], colonyRows: []);

        self::assertCount(1, $dispatched);
        self::assertSame('stale:'.self::COLONY_ID, $dispatched[0]['data']['subject']);
    }

    public function testAlertAlreadySentDuringTheCurrentEpisodeIsNotSentAgain(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-3 hours');
        $rows = [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_SOON)])];
        $sentAfterLastView = $this->notification('launchpad_empty', 'launchpad:'.self::COLONY_ID.':'.self::LAUNCHPAD_PIN_ID, '-1 hour');

        self::assertSame([], $this->evaluate([$colony], $rows, existingNotifications: [$sentAfterLastView]));
    }

    public function testAlertSentAtTheExactInstantOfTheLastViewIsNotSentAgain(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-3 hours');
        $rows = [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_SOON)])];
        $sentAtLastView = $this->notification('launchpad_empty', 'launchpad:'.self::COLONY_ID.':'.self::LAUNCHPAD_PIN_ID, '-3 hours');

        self::assertSame([], $this->evaluate([$colony], $rows, existingNotifications: [$sentAtLastView]));
    }

    public function testAlertIsSentAgainOnceTheColonyHasBeenSeenAfterTheLastNotification(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-30 minutes');
        $rows = [$this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_SOON)])];
        $sentBeforeLastView = $this->notification('launchpad_empty', 'launchpad:'.self::COLONY_ID.':'.self::LAUNCHPAD_PIN_ID, '-5 hours');

        $dispatched = $this->evaluate([$colony], $rows, existingNotifications: [$sentBeforeLastView]);

        self::assertCount(1, $dispatched);
        self::assertSame('launchpad_empty', $dispatched[0]['data']['kind']);
    }

    public function testAlertForAnotherSubjectOfTheSameKindIsSentSeparately(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-3 hours');
        $rows = [
            $this->launchpadRow($colony, self::LAUNCHPAD_PIN_ID, [$this->feed(self::BIOFUELS, 12.0, self::EMPTY_AT_SOON)]),
            $this->launchpadRow($colony, self::OTHER_LAUNCHPAD_PIN_ID, [$this->feed(self::PRECIOUS_METALS, 30.0, self::EMPTY_AT_ALREADY)]),
        ];
        $sentForFirstLaunchpad = $this->notification('launchpad_empty', 'launchpad:'.self::COLONY_ID.':'.self::LAUNCHPAD_PIN_ID, '-1 hour');

        $dispatched = $this->evaluate([$colony], $rows, existingNotifications: [$sentForFirstLaunchpad]);

        self::assertCount(1, $dispatched);
        self::assertSame('launchpad:'.self::COLONY_ID.':'.self::OTHER_LAUNCHPAD_PIN_ID, $dispatched[0]['data']['subject']);
        self::assertSame(Notification::LEVEL_CRITICAL, $dispatched[0]['level']);
    }

    public function testNotificationOfAnotherKindOnTheSameSubjectDoesNotSilenceTheAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-25 hours');
        $sentForSameSubject = $this->notification('storage_full', 'stale:'.self::COLONY_ID, '-1 hour');

        $dispatched = $this->evaluate([$colony], existingNotifications: [$sentForSameSubject]);

        self::assertCount(1, $dispatched);
        self::assertSame('data_stale', $dispatched[0]['data']['kind']);
    }

    public function testNotificationOfAnotherCategoryDoesNotSilenceTheAlert(): void
    {
        $colony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-25 hours');
        $priceNotification = $this->notification('data_stale', 'stale:'.self::COLONY_ID, '-1 hour', Notification::CATEGORY_PRICE);

        self::assertCount(1, $this->evaluate([$colony], existingNotifications: [$priceNotification]));
    }

    public function testEachColonyIsEvaluatedOnItsOwn(): void
    {
        $staleColony = $this->colony(self::COLONY_ID, self::PLANET_NAME, '-25 hours');
        $freshColony = $this->colony(self::OTHER_COLONY_ID, self::OTHER_PLANET_NAME, '-2 hours');

        $dispatched = $this->evaluate([$staleColony, $freshColony]);

        self::assertCount(1, $dispatched);
        self::assertSame('stale:'.self::COLONY_ID, $dispatched[0]['data']['subject']);
        self::assertSame(self::PLANET_NAME, $dispatched[0]['data']['planetName']);
    }

    private function evaluate(
        array $colonies,
        array $launchpadRows = [],
        int $windowMinutes = self::DEFAULT_WINDOW_MINUTES,
        array $existingNotifications = [],
        ?float $storageCapacityM3 = self::STORAGE_CAPACITY_M3,
        ?array $colonyRows = null,
    ): array {
        $clock = new MockClock(self::NOW, 'UTC');

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByCharacter')->willReturn($colonies);

        $calculator = $this->createStub(PlanetaryInputNeedsCalculator::class);
        $calculator->method('calculate')->willReturn([
            'colonies' => $colonyRows ?? array_map(fn (PlanetaryColony $colony): array => $this->colonyRow($colony, $clock), $colonies),
            'launchpads' => $launchpadRows,
            'extractions' => [],
            'weekly' => [],
            'complete' => true,
        ]);

        $invTypes = $this->sdeTypes($storageCapacityM3);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($invTypes, array_flip($typeIds)),
        );

        $resolver = $this->createStub(PlanetaryExpiryThresholdResolver::class);
        $resolver->method('minutesFor')->willReturn($windowMinutes);

        $notificationRepository = $this->createStub(NotificationRepository::class);
        $notificationRepository->method('findByUserAndCategorySince')->willReturnCallback(
            static fn (User $user, string $category, \DateTimeImmutable $since): array => array_values(array_filter(
                $existingNotifications,
                static fn (Notification $notification): bool => $notification->getCategory() === $category
                    && $notification->getCreatedAt() >= $since,
            )),
        );

        $dispatched = [];
        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (User $user, string $category, string $level, string $title, string $message, ?array $data = null, ?string $route = null) use (&$dispatched): void {
                $dispatched[] = [
                    'user' => $user,
                    'category' => $category,
                    'level' => $level,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'route' => $route,
                ];
            },
        );

        (new PlanetaryAlertEvaluator(
            $colonyRepository,
            $calculator,
            $invTypeRepository,
            $resolver,
            $notificationRepository,
            $dispatcher,
            $clock,
        ))->evaluate($this->character);

        return $dispatched;
    }

    private function sdeTypes(?float $storageCapacityM3): array
    {
        $storageGroup = (new InvGroup())->setGroupId(self::STORAGE_GROUP_ID);
        $spaceportGroup = (new InvGroup())->setGroupId(self::SPACEPORT_GROUP_ID);
        $commodityGroup = (new InvGroup())->setGroupId(self::BASIC_COMMODITIES_GROUP_ID);

        $types = [
            (new InvType())->setTypeId(self::STORAGE_FACILITY_TYPE_ID)->setGroup($storageGroup)->setCapacity($storageCapacityM3)->setVolume(0.0),
            (new InvType())->setTypeId(self::LAUNCHPAD_TYPE_ID)->setGroup($spaceportGroup)->setCapacity(self::LAUNCHPAD_CAPACITY_M3)->setVolume(0.0),
            (new InvType())->setTypeId(self::BIOFUELS)->setGroup($commodityGroup)->setVolume(self::BIOFUELS_VOLUME_M3),
            (new InvType())->setTypeId(self::PRECIOUS_METALS)->setGroup($commodityGroup)->setVolume(self::PRECIOUS_METALS_VOLUME_M3),
            (new InvType())->setTypeId(self::SUSPENDED_PLASMOIDS)->setGroup($commodityGroup)->setVolume(self::SUSPENDED_PLASMOIDS_VOLUME_M3),
            (new InvType())->setTypeId(self::UNMEASURED_CARGO)->setGroup($commodityGroup)->setVolume(null),
        ];

        $indexed = [];
        foreach ($types as $type) {
            $indexed[$type->getTypeId()] = $type;
        }

        return $indexed;
    }

    private function colony(string $id, string $planetName, string $lastViewedRelativeToNow, PlanetaryPin ...$pins): PlanetaryColony
    {
        $colony = new PlanetaryColony();
        (new \ReflectionProperty(PlanetaryColony::class, 'id'))->setValue($colony, Uuid::fromString($id));
        $colony->setCharacter($this->character);
        $colony->setPlanetName($planetName);
        $colony->setLastUpdate((new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify($lastViewedRelativeToNow));
        foreach ($pins as $pin) {
            $colony->addPin($pin);
        }

        return $colony;
    }

    private function storagePin(?array $contents): PlanetaryPin
    {
        return $this->pin(self::STORAGE_PIN_ID, self::STORAGE_FACILITY_TYPE_ID, $contents);
    }

    private function launchpadPin(?array $contents): PlanetaryPin
    {
        return $this->pin(self::LAUNCHPAD_PIN_ID, self::LAUNCHPAD_TYPE_ID, $contents);
    }

    private function pin(int $pinId, int $typeId, ?array $contents): PlanetaryPin
    {
        $pin = new PlanetaryPin();
        $pin->setPinId($pinId);
        $pin->setTypeId($typeId);
        $pin->setContents($contents);

        return $pin;
    }

    private function colonyRow(PlanetaryColony $colony, MockClock $clock): array
    {
        $ageSeconds = max(0, $clock->now()->getTimestamp() - $colony->getLastUpdate()->getTimestamp());

        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'planetId' => 0,
            'planetName' => $colony->getPlanetName(),
            'characterName' => self::CHARACTER_NAME,
            'complete' => true,
            'needs' => [],
            'lastViewAt' => $colony->getLastUpdate()->format('c'),
            'dataAgeSeconds' => $ageSeconds,
            'dataStale' => $ageSeconds > 86400,
        ];
    }

    private function launchpadRow(PlanetaryColony $colony, int $pinId, array $feeds): array
    {
        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'planetId' => 0,
            'planetName' => $colony->getPlanetName(),
            'pinId' => $pinId,
            'typeId' => self::LAUNCHPAD_TYPE_ID,
            'typeName' => 'Launchpad',
            'capacityM3' => self::LAUNCHPAD_CAPACITY_M3,
            'usedVolumeM3' => 0.0,
            'dailyVolumeM3' => 0.0,
            'feeds' => $feeds,
            'lastViewAt' => $colony->getLastUpdate()->format('c'),
            'dataAgeSeconds' => max(0, (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->getTimestamp() - $colony->getLastUpdate()->getTimestamp()),
            'dataStale' => false,
        ];
    }

    private function feed(int $typeId, float $dailyRate, ?string $emptyAt): array
    {
        return [
            'typeId' => $typeId,
            'typeName' => 'Type '.$typeId,
            'dailyRate' => $dailyRate,
            'stock' => 0.0,
            'projectedStock' => 0.0,
            'emptyAt' => $emptyAt,
            'volumePerUnit' => 0.5,
        ];
    }

    private function notification(string $kind, string $subject, string $createdAtRelativeToNow, string $category = Notification::CATEGORY_PLANETARY): Notification
    {
        $notification = (new Notification())
            ->setUser($this->user)
            ->setCategory($category)
            ->setLevel(Notification::LEVEL_WARNING)
            ->setTitle('Earlier alert')
            ->setMessage('Earlier alert message')
            ->setData(['kind' => $kind, 'subject' => $subject]);
        (new \ReflectionProperty(Notification::class, 'createdAt'))->setValue(
            $notification,
            (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify($createdAtRelativeToNow),
        );

        return $notification;
    }

    private function sorted(?array $data): array
    {
        self::assertIsArray($data);
        ksort($data);

        return $data;
    }
}
