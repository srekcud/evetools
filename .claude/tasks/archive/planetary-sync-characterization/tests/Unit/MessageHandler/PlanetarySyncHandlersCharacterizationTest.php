<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Message\SyncCharacterPlanetaryColonies;
use App\Message\SyncPlanetaryColonies;
use App\Message\TriggerPlanetarySync;
use App\MessageHandler\SyncCharacterPlanetaryColoniesHandler;
use App\MessageHandler\SyncPlanetaryColoniesHandler;
use App\MessageHandler\TriggerPlanetarySyncHandler;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\PlanetarySyncService;
use App\State\Processor\Planetary\SyncPlanetaryProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TriggerPlanetarySyncHandler::class)]
#[CoversClass(SyncPlanetaryColoniesHandler::class)]
#[CoversClass(SyncCharacterPlanetaryColoniesHandler::class)]
final class PlanetarySyncHandlersCharacterizationTest extends TestCase
{
    private const string PLANETARY_SCOPE = 'esi-planets.manage_planets.v1';
    private const string SELENE_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f21';
    private const string SELENE_ALT_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f22';
    private const string DORAN_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f23';
    private const string IVO_ID = '0192b3d1-7a4e-7c21-9f3a-5b6c7d8e9f24';

    private MessageBusInterface&Stub $messageBus;
    private CharacterRepository&Stub $characterRepository;
    private array $dispatchedMessages = [];
    private array $syncTrackerCalls = [];

    protected function setUp(): void
    {
        $this->messageBus = $this->createStub(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });
        $this->characterRepository = $this->createStub(CharacterRepository::class);
    }

    public function testTriggerDispatchesTheInstanceWideColoniesSync(): void
    {
        (new TriggerPlanetarySyncHandler($this->messageBus, new NullLogger()))(new TriggerPlanetarySync());

        $this->assertEquals([new SyncPlanetaryColonies()], $this->dispatchedMessages);
    }

    public function testColoniesSyncQueuesOneMessagePerActiveCharacterHoldingThePlanetaryScope(): void
    {
        $this->characterRepository->method('findActiveWithValidTokens')->willReturn([
            $this->character(self::SELENE_ID, [self::PLANETARY_SCOPE, 'esi-assets.read_assets.v1']),
            $this->character(self::SELENE_ALT_ID, ['esi-assets.read_assets.v1']),
            $this->character(self::DORAN_ID, [self::PLANETARY_SCOPE]),
            $this->character(self::IVO_ID, null),
            $this->character(null, [self::PLANETARY_SCOPE]),
        ]);

        $this->coloniesHandler($this->syncTracker())(new SyncPlanetaryColonies());

        $this->assertEquals([
            new SyncCharacterPlanetaryColonies(self::SELENE_ID),
            new SyncCharacterPlanetaryColonies(self::DORAN_ID),
        ], $this->dispatchedMessages);
        $this->assertSame([
            ['start', 'planetary'],
            ['complete', 'planetary', '2/5 chars queued'],
        ], $this->syncTrackerCalls);
    }

    public function testColoniesSyncFailureIsTrackedAndRethrown(): void
    {
        $failure = new \RuntimeException('connection to server lost');
        $this->characterRepository->method('findActiveWithValidTokens')->willThrowException($failure);

        $caught = null;
        try {
            $this->coloniesHandler($this->syncTracker())(new SyncPlanetaryColonies());
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame([
            ['start', 'planetary'],
            ['fail', 'planetary', 'connection to server lost'],
        ], $this->syncTrackerCalls);
        $this->assertSame([], $this->dispatchedMessages);
    }

    public function testCharacterSyncRunsTheColonySyncForTheFoundCharacter(): void
    {
        $character = $this->character(self::DORAN_ID, [self::PLANETARY_SCOPE]);
        $this->characterRepository->method('find')->willReturnCallback(
            static fn (Uuid $id): ?Character => $id->toRfc4122() === self::DORAN_ID ? $character : null,
        );
        $syncService = $this->createMock(PlanetarySyncService::class);
        $syncService->expects($this->once())->method('syncCharacterColonies')->with($this->identicalTo($character))->willReturn(3);

        (new SyncCharacterPlanetaryColoniesHandler($this->characterRepository, $syncService, new NullLogger()))(
            new SyncCharacterPlanetaryColonies(self::DORAN_ID),
        );
    }

    public function testCharacterSyncForUnknownCharacterSyncsNothing(): void
    {
        $this->characterRepository->method('find')->willReturn(null);
        $syncService = $this->createMock(PlanetarySyncService::class);
        $syncService->expects($this->never())->method('syncCharacterColonies');

        (new SyncCharacterPlanetaryColoniesHandler($this->characterRepository, $syncService, new NullLogger()))(
            new SyncCharacterPlanetaryColonies(self::IVO_ID),
        );
    }

    #[Group('pi-audit-4')]
    public function testSyncRequestedByOneUserCurrentlyQueuesTheCharactersOfEveryUserPiAudit4(): void
    {
        $selene = $this->character(self::SELENE_ID, [self::PLANETARY_SCOPE]);
        $doran = $this->character(self::DORAN_ID, [self::PLANETARY_SCOPE]);
        $requestingUser = new User();
        $requestingUser->addCharacter($selene);
        (new User())->addCharacter($doran);
        $this->characterRepository->method('findActiveWithValidTokens')->willReturn([$selene, $doran]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($requestingUser);

        (new SyncPlanetaryProcessor($security, $this->messageBus))->process(null, new Post());
        (new TriggerPlanetarySyncHandler($this->messageBus, new NullLogger()))($this->dispatchedMessages[0]);
        $this->coloniesHandler($this->createStub(SyncTracker::class))($this->dispatchedMessages[1]);

        $this->assertEquals([
            new TriggerPlanetarySync(),
            new SyncPlanetaryColonies(),
            new SyncCharacterPlanetaryColonies(self::SELENE_ID),
            new SyncCharacterPlanetaryColonies(self::DORAN_ID),
        ], $this->dispatchedMessages);
    }

    private function coloniesHandler(SyncTracker $syncTracker): SyncPlanetaryColoniesHandler
    {
        return new SyncPlanetaryColoniesHandler($this->characterRepository, $this->messageBus, new NullLogger(), $syncTracker);
    }

    private function syncTracker(): SyncTracker
    {
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('start')->willReturnCallback(function (string $type): void {
            $this->syncTrackerCalls[] = ['start', $type];
        });
        $syncTracker->method('complete')->willReturnCallback(function (string $type, ?string $message = null): void {
            $this->syncTrackerCalls[] = ['complete', $type, $message];
        });
        $syncTracker->method('fail')->willReturnCallback(function (string $type, string $message): void {
            $this->syncTrackerCalls[] = ['fail', $type, $message];
        });

        return $syncTracker;
    }

    private function character(?string $characterId, ?array $grantedScopes): Character
    {
        $character = new Character();
        if ($characterId !== null) {
            (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($characterId));
        }
        if ($grantedScopes !== null) {
            $token = $this->createStub(EveToken::class);
            $token->method('hasScope')->willReturnCallback(
                static fn (string $scope): bool => in_array($scope, $grantedScopes, true),
            );
            $character->setEveToken($token);
        }

        return $character;
    }
}
