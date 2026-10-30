<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\User;
use App\Message\SyncUserIndustryJobs;
use App\MessageHandler\SyncUserIndustryJobsHandler;
use App\Repository\UserRepository;
use App\Service\Sync\IndustryJobSyncService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncUserIndustryJobsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-215')]
final class SyncUserIndustryJobsHandlerTest extends TestCase
{
    private const string USER_ID = '0199a1f0-0000-7000-8000-000000215101';
    private const string OTHER_USER_ID = '0199a1f0-0000-7000-8000-000000215102';
    private const string DELETED_USER_ID = '0199a1f0-0000-7000-8000-000000215103';

    public function testSyncsTheJobsOfTheUserNamedByTheMessage(): void
    {
        $user = $this->user(self::USER_ID);
        $otherUser = $this->user(self::OTHER_USER_ID);

        $serviceCalls = [];
        $industryJobSyncService = $this->createStub(IndustryJobSyncService::class);
        $industryJobSyncService->method('syncUserJobs')->willReturnCallback(
            static function (User $syncedUser) use (&$serviceCalls, $user, $otherUser): void {
                $serviceCalls[] = match ($syncedUser) {
                    $user => 'syncUserJobs:' . self::USER_ID,
                    $otherUser => 'syncUserJobs:' . self::OTHER_USER_ID,
                    default => 'syncUserJobs:unknown',
                };
            },
        );

        $handler = new SyncUserIndustryJobsHandler(
            $this->repositoryHolding([self::USER_ID => $user, self::OTHER_USER_ID => $otherUser]),
            $industryJobSyncService,
            new NullLogger(),
        );

        $handler(new SyncUserIndustryJobs(self::USER_ID));

        self::assertSame(['syncUserJobs:' . self::USER_ID], $serviceCalls);
    }

    public function testUserDeletedSinceDispatchIsSkippedWithAWarning(): void
    {
        $industryJobSyncService = $this->createMock(IndustryJobSyncService::class);
        $industryJobSyncService->expects($this->never())->method('syncUserJobs');

        $logger = new BufferingLogger();
        $handler = new SyncUserIndustryJobsHandler(
            $this->repositoryHolding([self::USER_ID => $this->user(self::USER_ID)]),
            $industryJobSyncService,
            $logger,
        );

        $handler(new SyncUserIndustryJobs(self::DELETED_USER_ID));

        self::assertSame(
            [[LogLevel::WARNING, 'User not found for industry jobs sync', ['userId' => self::DELETED_USER_ID]]],
            $logger->cleanLogs(),
        );
    }

    public function testUserJobsSyncFailurePropagatesToTheBusAfterOneSyncOfThatUser(): void
    {
        $user = $this->user(self::USER_ID);

        $syncedUsers = [];
        $industryJobSyncService = $this->createStub(IndustryJobSyncService::class);
        $industryJobSyncService->method('syncUserJobs')->willReturnCallback(
            static function (User $syncedUser) use (&$syncedUsers): void {
                $syncedUsers[] = $syncedUser;

                throw new \RuntimeException('Connection refused');
            },
        );

        $handler = new SyncUserIndustryJobsHandler(
            $this->repositoryHolding([self::USER_ID => $user]),
            $industryJobSyncService,
            new NullLogger(),
        );

        $raised = null;
        try {
            $handler(new SyncUserIndustryJobs(self::USER_ID));
        } catch (\RuntimeException $exception) {
            $raised = $exception;
        }

        self::assertSame('Connection refused', $raised?->getMessage());
        self::assertSame([$user], $syncedUsers);
    }

    /** @param array<string, User> $usersById */
    private function repositoryHolding(array $usersById): UserRepository
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?User => $usersById[(string) $id] ?? null,
        );

        return $userRepository;
    }

    private function user(string $userId): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString($userId));

        return $user;
    }
}
