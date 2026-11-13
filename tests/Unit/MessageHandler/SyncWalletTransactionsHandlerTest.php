<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Message\SyncCharacterWalletTransactions;
use App\Message\SyncWalletTransactions;
use App\MessageHandler\SyncWalletTransactionsHandler;
use App\Repository\CharacterRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncWalletTransactionsHandler::class)]
#[Group('issue-916')]
#[AllowMockObjectsWithoutExpectations]
final class SyncWalletTransactionsHandlerTest extends TestCase
{
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';
    private const string NOW = '2026-11-13T21:04:00+00:00';

    public function testCharacterWhoseNextReadAtIsTheCurrentSecondIsQueued(): void
    {
        $character = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000001', [self::WALLET_SCOPE]);
        $reading = $this->readingOf($character, '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:00+00:00');

        self::assertSame([$character->getId()?->toRfc4122()], $this->queuedCharacterIds([$character], [$reading]));
    }

    public function testCharacterWhoseNextReadAtIsOneSecondAwayIsNotQueued(): void
    {
        $character = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000002', [self::WALLET_SCOPE]);
        $reading = $this->readingOf($character, '2026-11-13T20:04:10+00:00', '2026-11-13T21:04:01+00:00');

        self::assertSame([], $this->queuedCharacterIds([$character], [$reading]));
    }

    public function testCharacterWithAPastNextReadAtAfterAStoppedWorkerIsQueued(): void
    {
        $character = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000003', [self::WALLET_SCOPE]);
        $reading = $this->readingOf($character, '2026-11-12T08:00:00+00:00', '2026-11-12T09:00:00+00:00');

        self::assertSame([$character->getId()?->toRfc4122()], $this->queuedCharacterIds([$character], [$reading]));
    }

    public function testCharacterNeverReadIsQueued(): void
    {
        $character = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000004', [self::WALLET_SCOPE]);

        self::assertSame([$character->getId()?->toRfc4122()], $this->queuedCharacterIds([$character], []));
    }

    public function testCharacterWhoseOnlyReadFailedIsQueuedOnceItsRetryDelayIsOver(): void
    {
        $retryOver = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000005', [self::WALLET_SCOPE]);
        $retryPending = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000006', [self::WALLET_SCOPE]);
        $readings = [
            $this->readingOf($retryOver, null, '2026-11-13T21:03:00+00:00'),
            $this->readingOf($retryPending, null, '2026-11-13T21:19:00+00:00'),
        ];

        self::assertSame([$retryOver->getId()?->toRfc4122()], $this->queuedCharacterIds([$retryOver, $retryPending], $readings));
    }

    public function testCharacterWithoutTheWalletScopeIsNeverQueued(): void
    {
        $withoutScope = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000007', ['esi-skills.read_skills.v1']);
        $withoutToken = $this->characterWithoutToken('0199a1f0-0000-7000-8000-000000000008');

        self::assertSame([], $this->queuedCharacterIds([$withoutScope, $withoutToken], []));
    }

    public function testOnlyTheDueCharactersAreQueuedInTheOrderOfTheRepository(): void
    {
        $due = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000011', [self::WALLET_SCOPE]);
        $notDue = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000012', [self::WALLET_SCOPE]);
        $neverRead = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000013', [self::WALLET_SCOPE]);
        $withoutScope = $this->characterWithScopes('0199a1f0-0000-7000-8000-000000000014', []);
        $readings = [
            $this->readingOf($due, '2026-11-13T20:03:00+00:00', '2026-11-13T21:03:00+00:00'),
            $this->readingOf($notDue, '2026-11-13T20:30:00+00:00', '2026-11-13T21:30:00+00:00'),
        ];

        self::assertSame(
            [$due->getId()?->toRfc4122(), $neverRead->getId()?->toRfc4122()],
            $this->queuedCharacterIds([$due, $notDue, $neverRead, $withoutScope], $readings),
        );
    }

    private function queuedCharacterIds(array $activeCharacters, array $storedReadings): array
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')->willReturn($activeCharacters);

        $readingRepository = $this->createStub(CharacterWalletReadingRepository::class);
        $readingRepository->method('findByCharacters')->willReturn($storedReadings);
        $readingRepository->method('findOneByCharacter')->willReturnCallback(
            static function (Character $character) use ($storedReadings): ?CharacterWalletReading {
                foreach ($storedReadings as $reading) {
                    if ($reading->getCharacter() === $character) {
                        return $reading;
                    }
                }

                return null;
            },
        );

        $queued = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$queued): Envelope {
                \assert($message instanceof SyncCharacterWalletTransactions);
                $queued[] = $message->characterId;

                return new Envelope($message);
            },
        );

        $handler = new SyncWalletTransactionsHandler(
            $characterRepository,
            $messageBus,
            new NullLogger(),
            $this->createStub(SyncTracker::class),
            $readingRepository,
            new MockClock(new \DateTimeImmutable(self::NOW)),
        );

        $handler(new SyncWalletTransactions());

        return $queued;
    }

    private function characterWithScopes(string $characterId, array $grantedScopes): Character
    {
        $character = $this->characterWithoutToken($characterId);
        $character->setEveToken((new EveToken())->setScopes($grantedScopes));

        return $character;
    }

    private function characterWithoutToken(string $characterId): Character
    {
        $character = (new Character())->setName('Pilot ' . substr($characterId, -2));
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($characterId));

        return $character;
    }

    private function readingOf(Character $character, ?string $readAt, string $nextReadAt): CharacterWalletReading
    {
        return (new CharacterWalletReading())
            ->setCharacter($character)
            ->setReadAt($readAt === null ? null : new \DateTimeImmutable($readAt))
            ->setNextReadAt(new \DateTimeImmutable($nextReadAt));
    }
}
