<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\User;
use App\Message\SyncBlueprints;
use App\Message\SyncUserBlueprints;
use App\MessageHandler\SyncBlueprintsHandler;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncBlueprintsHandler::class)]
#[Group('issue-357')]
final class SyncBlueprintsHandlerTest extends TestCase
{
    private const string USER_U1 = '0199a1f0-0000-7000-8000-000000357101';
    private const string USER_U2 = '0199a1f0-0000-7000-8000-000000357102';

    public function testQueuesOneUserBlueprintsMessagePerActiveUserAndTracksTheBlueprintsSync(): void
    {
        $userU1 = $this->user(self::USER_U1);
        $userU2 = $this->user(self::USER_U2);
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')->willReturn([
            $this->character($userU1),
            $this->character($userU2),
            $this->character($userU1),
        ]);

        $dispatchedMessages = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $message;

                return new Envelope($message);
            },
        );

        $syncTrackerCalls = [];
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('start')->willReturnCallback(
            static function (string $type) use (&$syncTrackerCalls): void {
                $syncTrackerCalls[] = ['start', $type];
            },
        );
        $syncTracker->method('complete')->willReturnCallback(
            static function (string $type, ?string $message = null) use (&$syncTrackerCalls): void {
                $syncTrackerCalls[] = ['complete', $type, $message];
            },
        );

        (new SyncBlueprintsHandler($characterRepository, $messageBus, new NullLogger(), $syncTracker))(new SyncBlueprints());

        self::assertEquals(
            [new SyncUserBlueprints(self::USER_U1), new SyncUserBlueprints(self::USER_U2)],
            $dispatchedMessages,
        );
        self::assertSame(
            [['start', 'blueprints'], ['complete', 'blueprints', '2 users queued for 3 chars']],
            $syncTrackerCalls,
        );
    }

    private function user(string $userId): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString($userId));

        return $user;
    }

    private function character(User $user): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getUser')->willReturn($user);

        return $character;
    }
}
