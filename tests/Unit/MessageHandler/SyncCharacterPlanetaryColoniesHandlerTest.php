<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Message\SyncCharacterPlanetaryColonies;
use App\MessageHandler\SyncCharacterPlanetaryColoniesHandler;
use App\Repository\CharacterRepository;
use App\Repository\NotificationRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Planetary\PlanetaryAlertEvaluator;
use App\Service\Planetary\PlanetaryExpiryThresholdResolver;
use App\Service\Planetary\PlanetaryInputNeedsCalculator;
use App\Service\Sync\PlanetarySyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncCharacterPlanetaryColoniesHandler::class)]
#[Group('issue-1391')]
final class SyncCharacterPlanetaryColoniesHandlerTest extends TestCase
{
    private const string CHARACTER_ID = '0199a1f0-0000-7000-8000-0000000000bb';
    private const string COLONY_ID = '0199a1f0-0000-7000-8000-000000001391';
    private const string NOW = '2026-11-26 12:00:00';
    private const int STALE_WINDOW_MINUTES = 1440;

    public function testAlertsAreEvaluatedAfterTheCharacterColoniesAreSynced(): void
    {
        $character = $this->character();
        $events = [];
        $dispatched = [];

        $handler = $this->handler($character, $events, $dispatched, '-25 hours');

        $handler(new SyncCharacterPlanetaryColonies(self::CHARACTER_ID));

        self::assertSame(['sync', 'colonies read'], $events);
        self::assertCount(1, $dispatched);
        self::assertSame('Colony data is stale', $dispatched[0]);
    }

    public function testAlertsAreNotEvaluatedWhenTheCharacterNoLongerExists(): void
    {
        $events = [];
        $dispatched = [];

        $handler = $this->handler(null, $events, $dispatched, '-25 hours');

        $handler(new SyncCharacterPlanetaryColonies(self::CHARACTER_ID));

        self::assertSame([], $events);
        self::assertSame([], $dispatched);
    }

    private function handler(?Character $character, array &$events, array &$dispatched, string $lastViewRelativeToNow): SyncCharacterPlanetaryColoniesHandler
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturn($character);

        $syncService = $this->createStub(PlanetarySyncService::class);
        $syncService->method('syncCharacterColonies')->willReturnCallback(
            static function () use (&$events): int {
                $events[] = 'sync';

                return 1;
            },
        );

        $colony = new PlanetaryColony();
        (new \ReflectionProperty(PlanetaryColony::class, 'id'))->setValue($colony, Uuid::fromString(self::COLONY_ID));
        $colony->setPlanetName('Jita IV');
        $colony->setLastUpdate((new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify($lastViewRelativeToNow));
        if ($character !== null) {
            $colony->setCharacter($character);
        }

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByCharacter')->willReturnCallback(
            static function () use (&$events, $colony): array {
                $events[] = 'colonies read';

                return [$colony];
            },
        );

        $calculator = $this->createStub(PlanetaryInputNeedsCalculator::class);
        $calculator->method('calculate')->willReturn([
            'colonies' => [],
            'launchpads' => [],
            'extractions' => [],
            'weekly' => [],
            'complete' => true,
        ]);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturn([]);

        $resolver = $this->createStub(PlanetaryExpiryThresholdResolver::class);
        $resolver->method('minutesFor')->willReturn(self::STALE_WINDOW_MINUTES);

        $notificationRepository = $this->createStub(NotificationRepository::class);
        $notificationRepository->method('findByUserAndCategorySince')->willReturn([]);

        $dispatcher = $this->createStub(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (User $user, string $category, string $level, string $title) use (&$dispatched): void {
                $dispatched[] = $title;
            },
        );

        $evaluator = new PlanetaryAlertEvaluator(
            $colonyRepository,
            $calculator,
            $invTypeRepository,
            $resolver,
            $notificationRepository,
            $dispatcher,
            new MockClock(self::NOW, 'UTC'),
        );

        return new SyncCharacterPlanetaryColoniesHandler($characterRepository, $syncService, $evaluator, new NullLogger());
    }

    private function character(): Character
    {
        $character = (new Character())->setName('Ilsa Vance');
        (new User())->addCharacter($character);

        return $character;
    }
}
