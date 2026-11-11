<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\User;
use App\Message\SyncUserCharacterOrders;
use App\MessageHandler\SyncUserCharacterOrdersHandler;
use App\Repository\UserRepository;
use App\Service\Sync\CharacterOrderSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncUserCharacterOrdersHandler::class)]
#[Group('issue-350')]
final class SyncUserCharacterOrdersHandlerTest extends TestCase
{
    private const string USER_ID = '0199a1f0-0000-7000-8000-000000350201';

    public function testSyncsTheSellOrdersOfTheUserOfTheMessage(): void
    {
        $user = $this->createStub(User::class);
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?User => $id instanceof Uuid && $id->toRfc4122() === self::USER_ID ? $user : null,
        );
        $orderSyncService = $this->createMock(CharacterOrderSyncService::class);
        $orderSyncService->expects(self::once())->method('syncUserOrders')->with($user);

        (new SyncUserCharacterOrdersHandler($userRepository, $orderSyncService, new NullLogger()))(new SyncUserCharacterOrders(self::USER_ID));
    }
}
