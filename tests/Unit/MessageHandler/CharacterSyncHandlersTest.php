<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Repository\CharacterRepository;
use App\Service\Sync\PlanetarySyncService;
use App\Service\Sync\WalletTransactionSyncService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #30 : les messages par personnage dispatchés par les syncs planifiées
 * (wallet transactions, colonies PI) synchronisent un seul personnage.
 *
 * Contrat de la phase verte, calqué sur SyncCharacterAssets / SyncCharacterAssetsHandler :
 * - message `App\Message\SyncCharacterXxx(string $characterId)` (UUID RFC 4122 du Character) ;
 * - handler `App\MessageHandler\SyncCharacterXxxHandler(CharacterRepository, <service de sync>, LoggerInterface)`.
 */
#[AllowMockObjectsWithoutExpectations]
final class CharacterSyncHandlersTest extends TestCase
{
    private const string CHARACTER_ID = '0199a1f0-0000-7000-8000-0000000000aa';

    /** @return iterable<string, array{string, string, class-string, string}> */
    public static function characterSyncs(): iterable
    {
        // per-character message, its handler, sync service, per-character sync method
        yield 'wallet transactions' => [
            'App\Message\SyncCharacterWalletTransactions',
            'App\MessageHandler\SyncCharacterWalletTransactionsHandler',
            WalletTransactionSyncService::class,
            'syncCharacterTransactions',
        ];
        yield 'planetary colonies' => [
            'App\Message\SyncCharacterPlanetaryColonies',
            'App\MessageHandler\SyncCharacterPlanetaryColoniesHandler',
            PlanetarySyncService::class,
            'syncCharacterColonies',
        ];
    }

    /** @param class-string $syncServiceClass */
    #[DataProvider('characterSyncs')]
    public function testSyncsOnlyTheCharacterNamedByTheMessage(
        string $messageClass,
        string $handlerClass,
        string $syncServiceClass,
        string $characterSyncMethod,
    ): void {
        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::fromString(self::CHARACTER_ID));

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?Character => (string) $id === self::CHARACTER_ID ? $character : null,
        );

        $syncedCharacters = [];
        $handler = $this->handler($handlerClass, $characterRepository, $syncServiceClass, $characterSyncMethod, $syncedCharacters);

        $handler(new $messageClass(self::CHARACTER_ID));

        self::assertSame([$character], $syncedCharacters);
    }

    /** @param class-string $syncServiceClass */
    #[DataProvider('characterSyncs')]
    public function testCharacterDeletedSinceDispatchIsSkippedWithoutError(
        string $messageClass,
        string $handlerClass,
        string $syncServiceClass,
        string $characterSyncMethod,
    ): void {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturn(null);

        $syncedCharacters = [];
        $handler = $this->handler($handlerClass, $characterRepository, $syncServiceClass, $characterSyncMethod, $syncedCharacters);

        $handler(new $messageClass(self::CHARACTER_ID));

        self::assertSame([], $syncedCharacters);
    }

    /**
     * @param class-string    $syncServiceClass
     * @param list<Character> $syncedCharacters
     */
    private function handler(
        string $handlerClass,
        CharacterRepository $characterRepository,
        string $syncServiceClass,
        string $characterSyncMethod,
        array &$syncedCharacters,
    ): callable {
        $syncService = $this->createStub($syncServiceClass);
        $syncService->method($characterSyncMethod)->willReturnCallback(
            // Returns 0 synced colonies for syncCharacterColonies(); ignored by the void sync methods
            static function (Character $character) use (&$syncedCharacters): int {
                $syncedCharacters[] = $character;

                return 0;
            },
        );

        return new $handlerClass($characterRepository, $syncService, new NullLogger());
    }
}
