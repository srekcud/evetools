<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\User;
use App\Message\SyncUserBlueprints;
use App\MessageHandler\SyncUserBlueprintsHandler;
use App\Repository\UserRepository;
use App\Service\Sync\BlueprintSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncUserBlueprintsHandler::class)]
#[Group('issue-357')]
final class SyncUserBlueprintsHandlerTest extends TestCase
{
    private const string USER_ID = '0199a1f0-0000-7000-8000-000000357201';

    public function testSyncsTheBlueprintsOfTheUserOfTheMessage(): void
    {
        $user = $this->createStub(User::class);
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?User => $id instanceof Uuid && $id->toRfc4122() === self::USER_ID ? $user : null,
        );
        $blueprintSyncService = $this->createMock(BlueprintSyncService::class);
        $blueprintSyncService->expects(self::once())->method('syncUserBlueprints')->with($user);

        (new SyncUserBlueprintsHandler($userRepository, $blueprintSyncService, new NullLogger()))(new SyncUserBlueprints(self::USER_ID));
    }
}
