<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Message\SyncIndustryJobs;
use App\Message\SyncUserIndustryJobs;
use App\MessageHandler\SyncIndustryJobsHandler;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncIndustryJobsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-215')]
final class SyncIndustryJobsHandlerTest extends TestCase
{
    private const string INDUSTRY_JOBS_SCOPE = 'esi-industry.read_character_jobs.v1';
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    private const string USER_U1 = '0199a1f0-0000-7000-8000-000000215001';
    private const string USER_U2 = '0199a1f0-0000-7000-8000-000000215002';

    private const string CHARACTER_A1 = '0199a1f0-0000-7000-8000-0000000000a1';
    private const string CHARACTER_A2 = '0199a1f0-0000-7000-8000-0000000000a2';
    private const string CHARACTER_A3 = '0199a1f0-0000-7000-8000-0000000000a3';
    private const string CHARACTER_B1 = '0199a1f0-0000-7000-8000-0000000000b1';
    private const string CHARACTER_C1 = '0199a1f0-0000-7000-8000-0000000000c1';
    private const string CHARACTER_D1 = '0199a1f0-0000-7000-8000-0000000000d1';

    /** @var list<object> */
    private array $dispatchedMessages = [];

    /** @var list<list<string|null>> */
    private array $syncTrackerCalls = [];

    public function testQueuesOneUserIndustryJobsMessagePerDistinctUserInOrderOfFirstAppearance(): void
    {
        $handler = $this->handler($this->charactersOfTwoUsers());

        $handler(new SyncIndustryJobs());

        self::assertEquals(
            [new SyncUserIndustryJobs(self::USER_U1), new SyncUserIndustryJobs(self::USER_U2)],
            $this->dispatchedMessages,
        );
    }

    public function testSyncTrackerCompletesWithQueuedUsersForCharactersRead(): void
    {
        $handler = $this->handler($this->charactersOfTwoUsers());

        $handler(new SyncIndustryJobs());

        self::assertSame(
            [['start', 'industry'], ['complete', 'industry', '2 users queued for 4 chars']],
            $this->syncTrackerCalls,
        );
    }

    public function testCharacterWithoutUserQueuesNothing(): void
    {
        $handler = $this->handler([
            $this->character(self::CHARACTER_C1, null, [self::INDUSTRY_JOBS_SCOPE]),
            $this->character(self::CHARACTER_A1, $this->user(self::USER_U1), [self::INDUSTRY_JOBS_SCOPE]),
        ]);

        $handler(new SyncIndustryJobs());

        self::assertEquals([new SyncUserIndustryJobs(self::USER_U1)], $this->dispatchedMessages);
        self::assertSame(['complete', 'industry', '1 users queued for 2 chars'], $this->syncTrackerCalls[1] ?? null);
    }

    public function testCharacterWhoseUserHasNoIdQueuesNothing(): void
    {
        $handler = $this->handler([
            $this->character(self::CHARACTER_D1, $this->user(null), [self::INDUSTRY_JOBS_SCOPE]),
            $this->character(self::CHARACTER_B1, $this->user(self::USER_U2), [self::WALLET_SCOPE]),
        ]);

        $handler(new SyncIndustryJobs());

        self::assertEquals([new SyncUserIndustryJobs(self::USER_U2)], $this->dispatchedMessages);
        self::assertSame(['complete', 'industry', '1 users queued for 2 chars'], $this->syncTrackerCalls[1] ?? null);
    }

    public function testSyncTrackerFailsAndErrorPropagatesWhenQueueingFails(): void
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')->willReturn([
            $this->character(self::CHARACTER_A1, $this->user(self::USER_U1), [self::INDUSTRY_JOBS_SCOPE]),
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

    /** @return list<Character> */
    private function charactersOfTwoUsers(): array
    {
        $userU1 = $this->user(self::USER_U1);
        $userU2 = $this->user(self::USER_U2);

        return [
            $this->character(self::CHARACTER_A1, $userU1, [self::INDUSTRY_JOBS_SCOPE]),
            $this->character(self::CHARACTER_B1, $userU2, [self::WALLET_SCOPE]),
            $this->character(self::CHARACTER_A2, $userU1, [self::INDUSTRY_JOBS_SCOPE]),
            $this->character(self::CHARACTER_A3, $userU1, [self::WALLET_SCOPE, self::INDUSTRY_JOBS_SCOPE]),
        ];
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

    private function user(?string $userId): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($userId === null ? null : Uuid::fromString($userId));

        return $user;
    }

    /** @param list<string> $grantedScopes */
    private function character(string $characterId, ?User $user, array $grantedScopes): Character
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturnCallback(
            static fn (string $scope): bool => in_array($scope, $grantedScopes, true),
        );

        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::fromString($characterId));
        $character->method('getName')->willReturn('Pilot ' . substr($characterId, -2));
        $character->method('getEveToken')->willReturn($token);
        $character->method('getUser')->willReturn($user);

        return $character;
    }
}
