<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\PurgeExpiredSharedLists;
use App\MessageHandler\PurgeExpiredSharedListsHandler;
use App\Repository\SharedShoppingListRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #34 : la purge quotidienne supprime les listes partagées expirées.
 */
#[CoversClass(PurgeExpiredSharedListsHandler::class)]
final class PurgeExpiredSharedListsHandlerTest extends TestCase
{
    public function testPurgeDeletesExpiredSharedLists(): void
    {
        $sharedListRepository = $this->createMock(SharedShoppingListRepository::class);
        $sharedListRepository->expects($this->once())
            ->method('deleteExpired')
            ->willReturn(3);

        $handler = new PurgeExpiredSharedListsHandler($sharedListRepository, new NullLogger());

        $handler(new PurgeExpiredSharedLists());
    }
}
