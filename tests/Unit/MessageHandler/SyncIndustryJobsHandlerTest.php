<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Message\SyncCharacterIndustryJobs;
use App\Message\SyncIndustryJobs;
use App\MessageHandler\SyncIndustryJobsHandler;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncIndustryJobsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class SyncIndustryJobsHandlerTest extends TestCase
{
    private const string INDUSTRY_JOBS_SCOPE = 'esi-industry.read_character_jobs.v1';
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    private const string CHARACTER_WITH_JOBS_SCOPE_A = '0199a1f0-0000-7000-8000-0000000000a1';
    private const string CHARACTER_WITH_WALLET_SCOPE_ONLY = '0199a1f0-0000-7000-8000-0000000000b2';
    private const string CHARACTER_WITH_JOBS_SCOPE_C = '0199a1f0-0000-7000-8000-0000000000c3';

    /** @var list<object> */
    private array $dispatchedMessages = [];

    /** @var list<list<string|null>> */
    private array $syncTrackerCalls = [];

    public function testQueuesOneCharacterIndustryJobsMessagePerCharacterHoldingTheJobsScope(): void
    {
        $handler = $this->handler([
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_A, [self::INDUSTRY_JOBS_SCOPE]),
            $this->character(self::CHARACTER_WITH_WALLET_SCOPE_ONLY, [self::WALLET_SCOPE]),
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_C, [self::WALLET_SCOPE, self::INDUSTRY_JOBS_SCOPE]),
        ]);

        $handler(new SyncIndustryJobs());

        self::assertEquals(
            [
                new SyncCharacterIndustryJobs(self::CHARACTER_WITH_JOBS_SCOPE_A),
                new SyncCharacterIndustryJobs(self::CHARACTER_WITH_JOBS_SCOPE_C),
            ],
            $this->dispatchedMessages,
        );
    }

    public function testSyncTrackerCompletesWithQueuedCharactersOutOfAllCharacters(): void
    {
        $handler = $this->handler([
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_A, [self::INDUSTRY_JOBS_SCOPE]),
            $this->character(self::CHARACTER_WITH_WALLET_SCOPE_ONLY, [self::WALLET_SCOPE]),
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_C, [self::INDUSTRY_JOBS_SCOPE]),
        ]);

        $handler(new SyncIndustryJobs());

        self::assertSame(
            [['start', 'industry'], ['complete', 'industry', '2/3 chars queued']],
            $this->syncTrackerCalls,
        );
    }

    public function testCharacterWithoutTokenIsNotQueued(): void
    {
        $characterWithoutToken = $this->createStub(Character::class);
        $characterWithoutToken->method('getId')->willReturn(Uuid::fromString(self::CHARACTER_WITH_WALLET_SCOPE_ONLY));
        $characterWithoutToken->method('getEveToken')->willReturn(null);

        $handler = $this->handler([
            $characterWithoutToken,
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_C, [self::INDUSTRY_JOBS_SCOPE]),
        ]);

        $handler(new SyncIndustryJobs());

        self::assertEquals([new SyncCharacterIndustryJobs(self::CHARACTER_WITH_JOBS_SCOPE_C)], $this->dispatchedMessages);
        self::assertSame(['complete', 'industry', '1/2 chars queued'], $this->syncTrackerCalls[1] ?? null);
    }

    public function testCharacterWithoutIdIsNotQueued(): void
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);
        $characterWithoutId = $this->createStub(Character::class);
        $characterWithoutId->method('getId')->willReturn(null);
        $characterWithoutId->method('getEveToken')->willReturn($token);

        $handler = $this->handler([
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_A, [self::INDUSTRY_JOBS_SCOPE]),
            $characterWithoutId,
            $this->character(self::CHARACTER_WITH_WALLET_SCOPE_ONLY, [self::WALLET_SCOPE]),
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_C, [self::INDUSTRY_JOBS_SCOPE]),
        ]);

        $handler(new SyncIndustryJobs());

        self::assertEquals(
            [
                new SyncCharacterIndustryJobs(self::CHARACTER_WITH_JOBS_SCOPE_A),
                new SyncCharacterIndustryJobs(self::CHARACTER_WITH_JOBS_SCOPE_C),
            ],
            $this->dispatchedMessages,
        );
        self::assertSame(['complete', 'industry', '2/4 chars queued'], $this->syncTrackerCalls[1] ?? null);
    }

    public function testSyncTrackerFailsAndErrorPropagatesWhenQueueingFails(): void
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')->willReturn([
            $this->character(self::CHARACTER_WITH_JOBS_SCOPE_A, [self::INDUSTRY_JOBS_SCOPE]),
        ]);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new \RuntimeException('async transport unreachable'));

        $handler = new SyncIndustryJobsHandler($characterRepository, $messageBus, new NullLogger(), $this->syncTracker());

        $raised = null;
        try {
            $handler(new SyncIndustryJobs());
        } catch (\RuntimeException $exception) {
            $raised = $exception;
        }

        self::assertSame('async transport unreachable', $raised?->getMessage());
        self::assertSame(
            [['start', 'industry'], ['fail', 'industry', 'async transport unreachable']],
            $this->syncTrackerCalls,
        );
    }

    /** @param list<Character> $characters */
    private function handler(array $characters): SyncIndustryJobsHandler
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')->willReturn($characters);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                $this->dispatchedMessages[] = $message;

                return new Envelope($message);
            },
        );

        return new SyncIndustryJobsHandler($characterRepository, $messageBus, new NullLogger(), $this->syncTracker());
    }

    private function syncTracker(): SyncTracker
    {
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('start')->willReturnCallback(
            function (string $type): void {
                $this->syncTrackerCalls[] = ['start', $type];
            },
        );
        $syncTracker->method('complete')->willReturnCallback(
            function (string $type, ?string $message = null): void {
                $this->syncTrackerCalls[] = ['complete', $type, $message];
            },
        );
        $syncTracker->method('fail')->willReturnCallback(
            function (string $type, string $error): void {
                $this->syncTrackerCalls[] = ['fail', $type, $error];
            },
        );

        return $syncTracker;
    }

    /** @param list<string> $grantedScopes */
    private function character(string $characterId, array $grantedScopes): Character
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturnCallback(
            static fn (string $scope): bool => in_array($scope, $grantedScopes, true),
        );

        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::fromString($characterId));
        $character->method('getName')->willReturn('Pilot ' . substr($characterId, -2));
        $character->method('getEveToken')->willReturn($token);

        return $character;
    }
}
