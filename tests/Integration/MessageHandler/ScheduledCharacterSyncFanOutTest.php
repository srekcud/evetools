<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Message\SyncPlanetaryColonies;
use App\Message\SyncWalletTransactions;
use App\MessageHandler\SyncPlanetaryColoniesHandler;
use App\MessageHandler\SyncWalletTransactionsHandler;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\PlanetarySyncService;
use App\Service\Sync\WalletTransactionSyncService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #30 : une sync planifiée traitée par le worker ne doit plus boucler sur tous les personnages
 * (appels ESI + usleep en ligne) ; elle dispatche un message par personnage éligible vers `async`.
 *
 * Kernel réel, sans base : le dépôt de personnages, les services de sync (frontière ESI) et le SyncTracker
 * sont doublés ; le bus et le routing messenger (transport `async` en mémoire en test) sont les vrais.
 */
#[CoversClass(SyncWalletTransactionsHandler::class)]
#[CoversClass(SyncPlanetaryColoniesHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class ScheduledCharacterSyncFanOutTest extends KernelTestCase
{
    private const string CHARACTER_WITH_SCOPE_A = '0199a1f0-0000-7000-8000-00000000000a';
    private const string CHARACTER_WITH_SCOPE_B = '0199a1f0-0000-7000-8000-00000000000b';
    private const string CHARACTER_WITHOUT_SCOPE = '0199a1f0-0000-7000-8000-00000000000c';

    /** @return iterable<string, array{object, string, string, class-string, string, string, string}> */
    public static function scheduledCharacterSyncs(): iterable
    {
        // scheduled message, transport it is received from, required ESI scope,
        // sync service, per-character sync method, expected per-character message, SyncTracker key
        yield 'wallet transactions' => [
            new SyncWalletTransactions(),
            'scheduler_default',
            'esi-wallet.read_character_wallet.v1',
            WalletTransactionSyncService::class,
            'syncCharacterTransactions',
            'App\Message\SyncCharacterWalletTransactions',
            'wallet',
        ];
        // Scheduled as TriggerPlanetarySync, which already sends SyncPlanetaryColonies to `async`
        yield 'planetary colonies' => [
            new SyncPlanetaryColonies(),
            'async',
            'esi-planets.manage_planets.v1',
            PlanetarySyncService::class,
            'syncCharacterColonies',
            'App\Message\SyncCharacterPlanetaryColonies',
            'planetary',
        ];
    }

    /**
     * @param class-string $syncServiceClass
     */
    #[DataProvider('scheduledCharacterSyncs')]
    public function testScheduledSyncQueuesOneAsyncMessagePerCharacterHoldingTheScope(
        object $scheduledMessage,
        string $receivedFromTransport,
        string $requiredScope,
        string $syncServiceClass,
        string $characterSyncMethod,
        string $expectedCharacterMessageClass,
        string $syncTrackerKey,
    ): void {
        self::bootKernel();
        $container = self::getContainer();

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findActiveWithValidTokens')->willReturn([
            $this->characterWithScopes(self::CHARACTER_WITH_SCOPE_A, [$requiredScope]),
            $this->characterWithScopes(self::CHARACTER_WITHOUT_SCOPE, []),
            $this->characterWithScopes(self::CHARACTER_WITH_SCOPE_B, [$requiredScope]),
        ]);
        $container->set(CharacterRepository::class, $characterRepository);

        $charactersSyncedInline = [];
        $syncService = $this->createStub($syncServiceClass);
        $syncService->method($characterSyncMethod)->willReturnCallback(
            // Returns 0 synced colonies for syncCharacterColonies(); ignored by the void sync methods
            static function (Character $character) use (&$charactersSyncedInline): int {
                $charactersSyncedInline[] = $character->getId()?->toRfc4122();

                return 0;
            },
        );
        $container->set($syncServiceClass, $syncService);

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
        $container->set(SyncTracker::class, $syncTracker);

        /** @var MessageBusInterface $bus */
        $bus = $container->get(MessageBusInterface::class);
        // Received from a transport: handled inline by the worker, never re-sent
        $bus->dispatch(new Envelope($scheduledMessage, [new ReceivedStamp($receivedFromTransport)]));

        /** @var InMemoryTransport $asyncTransport */
        $asyncTransport = $container->get('messenger.transport.async');
        $queued = array_map(
            static fn (Envelope $envelope): array => [$envelope->getMessage()::class, $envelope->getMessage()->characterId ?? null],
            $asyncTransport->getSent(),
        );

        self::assertSame(
            [
                [$expectedCharacterMessageClass, self::CHARACTER_WITH_SCOPE_A],
                [$expectedCharacterMessageClass, self::CHARACTER_WITH_SCOPE_B],
            ],
            $queued,
        );
        self::assertSame([], $charactersSyncedInline, 'no character may be synced (ESI) inside the scheduled message');
        self::assertSame(
            [['start', $syncTrackerKey], ['complete', $syncTrackerKey, '2/3 chars queued']],
            $syncTrackerCalls,
        );
    }

    /** @param list<string> $grantedScopes */
    private function characterWithScopes(string $characterId, array $grantedScopes): Character
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturnCallback(
            static fn (string $scope): bool => in_array($scope, $grantedScopes, true),
        );

        $character = $this->createStub(Character::class);
        $character->method('getId')->willReturn(Uuid::fromString($characterId));
        $character->method('getName')->willReturn('Pilot ' . substr($characterId, -1));
        $character->method('getEveToken')->willReturn($token);

        return $character;
    }
}
