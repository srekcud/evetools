<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\CharacterWalletReading;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\Industry\Project\AutomaticPurchaseLinker;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\WalletTransactionSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(WalletTransactionSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class WalletTransactionSyncServiceTest extends TestCase
{
    private EsiClient&MockObject $esiClient;
    private CachedWalletTransactionRepository&MockObject $transactionRepository;
    private EntityManagerInterface&MockObject $em;
    private HubInterface&MockObject $hub;
    private AutomaticPurchaseLinker&MockObject $purchaseLinker;
    private CharacterWalletReadingRepository&MockObject $readingRepository;
    private WalletTransactionSyncService $service;

    private array $persisted = [];

    /** @var list<array{character: Character, readAt: ?\DateTimeImmutable, nextReadAt: \DateTimeImmutable}> */
    private array $recordedReadings = [];

    private ?\Throwable $recordFailure = null;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->transactionRepository = $this->createMock(CachedWalletTransactionRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->purchaseLinker = $this->createMock(AutomaticPurchaseLinker::class);
        $this->readingRepository = $this->createMock(CharacterWalletReadingRepository::class);
        $this->persisted = [];
        $this->recordedReadings = [];
        $this->recordFailure = null;
        $this->readingRepository->method('record')->willReturnCallback(
            function (Character $character, ?\DateTimeImmutable $readAt, \DateTimeImmutable $nextReadAt): void {
                if ($this->recordFailure !== null) {
                    throw $this->recordFailure;
                }

                $this->recordedReadings[] = ['character' => $character, 'readAt' => $readAt, 'nextReadAt' => $nextReadAt];
            },
        );
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $mercurePublisher = new MercurePublisherService($this->hub, new NullLogger());

        $this->service = new WalletTransactionSyncService(
            $this->esiClient,
            $this->transactionRepository,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
            $this->purchaseLinker,
            $this->readingRepository,
            new MockClock(new \DateTimeImmutable('2026-11-13T20:04:10+00:00')),
        );
    }

    // ===========================================
    // Early returns -- no token or no scope
    // ===========================================

    public function testNoTokenReturnsEarly(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);

        $this->esiClient->expects($this->never())->method('getWithExpiry');
        $this->em->expects($this->never())->method('flush');
        $this->readingRepository->expects($this->never())->method('findOneByCharacter');

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->recordedReadings);
    }

    public function testNoScopeReturnsEarly(): void
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(false);

        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);

        $this->esiClient->expects($this->never())->method('getWithExpiry');
        $this->em->expects($this->never())->method('flush');
        $this->readingRepository->expects($this->never())->method('findOneByCharacter');

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->recordedReadings);
    }

    // ===========================================
    // New transaction creation
    // ===========================================

    public function testNewTransactionsArePersisted(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([
                $this->makeTransactionData(1001),
                $this->makeTransactionData(1002),
            ]),
            $this->page([]), // second page empty, stops pagination
        );

        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([1001, 1002], $this->persistedTransactionIds());
    }

    // ===========================================
    // Deduplication -- existing transactions skipped
    // ===========================================

    public function testExistingTransactionsAreSkipped(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([
            $this->makeTransactionData(2001),
            $this->makeTransactionData(2002),
        ]));

        // Both already exist
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([2001, 2002]);

        $this->em->expects($this->atLeastOnce())->method('flush');

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([], $this->persistedTransactionIds());
    }

    public function testMixOfNewAndExistingTransactions(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([
                $this->makeTransactionData(3001),
                $this->makeTransactionData(3002),
                $this->makeTransactionData(3003),
            ]),
            $this->page([]), // second page empty, stops pagination
        );

        // Only 3001 exists
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([3001]);

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([3002, 3003], $this->persistedTransactionIds());
    }

    public function testOnlyTheNewTransactionsAreHandedToTheAutomaticPurchaseLinker(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([$this->makeTransactionData(7001), $this->makeTransactionData(7002)]),
            $this->page([]),
        );
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([7001]);

        $this->purchaseLinker->expects($this->once())
            ->method('linkNewPurchases')
            ->with(
                $character,
                $this->callback(static fn (array $transactions): bool => array_map(
                    static fn (CachedWalletTransaction $transaction): int => $transaction->getTransactionId(),
                    $transactions,
                ) === [7002]),
            );

        $this->service->syncCharacterTransactions($character);
    }

    public function testAutomaticPurchaseLinkerIsNotCalledWhenNothingIsNew(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([$this->makeTransactionData(7101)]));
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([7101]);

        $this->purchaseLinker->expects($this->never())->method('linkNewPurchases');

        $this->service->syncCharacterTransactions($character);
    }

    public function testAutomaticPurchaseLinkerIsNotCalledWhenTheEsiFetchFails(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willThrowException(new \RuntimeException('ESI down'));

        $this->purchaseLinker->expects($this->never())->method('linkNewPurchases');

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // Pagination -- from_id logic
    // ===========================================

    public function testPaginationStopsWhenEsiReturnsEmptyPage(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->expects($this->exactly(2))
            ->method('getWithExpiry')
            ->willReturnCallback(function (string $url) {
                if (!str_contains($url, 'from_id')) {
                    return $this->page([
                        $this->makeTransactionData(5001),
                        $this->makeTransactionData(5002),
                    ]);
                }

                return $this->page([]);
            });

        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->service->syncCharacterTransactions($character);
    }

    public function testPaginationUsesLowestIdAsFromId(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $callCount = 0;
        $this->esiClient->expects($this->exactly(2))
            ->method('getWithExpiry')
            ->willReturnCallback(function (string $url) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertStringNotContainsString('from_id', $url);

                    return $this->page([
                        $this->makeTransactionData(200),
                        $this->makeTransactionData(100), // lowest ID
                        $this->makeTransactionData(300),
                    ]);
                }

                // Second call should use from_id=100 (the lowest)
                $this->assertStringContainsString('from_id=100', $url);

                return $this->page([]);
            });

        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // Stop when no new transactions on a page
    // ===========================================

    public function testStopsWhenAllTransactionsOnPageAlreadyExist(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $callCount = 0;
        $this->esiClient->expects($this->exactly(2))
            ->method('getWithExpiry')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->page([
                        $this->makeTransactionData(6001),
                        $this->makeTransactionData(6002),
                    ]);
                }

                // Second page: all transactions already exist
                return $this->page([
                    $this->makeTransactionData(6003),
                    $this->makeTransactionData(6004),
                ]);
            });

        $this->transactionRepository->method('findExistingTransactionIds')
            ->willReturnCallback(function (array $ids) {
                // First page: none exist (new)
                if (in_array(6001, $ids, true)) {
                    return [];
                }

                // Second page: all exist
                return [6003, 6004];
            });

        $this->service->syncCharacterTransactions($character);

        // Only 2 persists (from first page), second page is all duplicates
        $this->assertSame([6001, 6002], $this->persistedTransactionIds());
    }

    // ===========================================
    // Mercure notifications
    // ===========================================

    public function testMercureSyncStartedAndCompletedPublished(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([]));

        $publishedPayloads = [];
        $this->hub->method('publish')
            ->willReturnCallback(function (Update $update) use (&$publishedPayloads) {
                $publishedPayloads[] = json_decode((string) $update->getData(), true);

                return 'id';
            });

        $this->service->syncCharacterTransactions($character);

        // Should have published at least 2 updates: started + completed
        $this->assertGreaterThanOrEqual(2, count($publishedPayloads));

        $statuses = array_column($publishedPayloads, 'status');
        $this->assertContains('started', $statuses);
        $this->assertContains('completed', $statuses);

        // All updates should be for wallet-transactions syncType
        foreach ($publishedPayloads as $payload) {
            $this->assertSame('wallet-transactions', $payload['syncType']);
        }
    }

    public function testMercureProgressPublishedOnPagination(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $callCount = 0;
        $this->esiClient->method('getWithExpiry')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->page([
                        $this->makeTransactionData(7001),
                        $this->makeTransactionData(7002),
                    ]);
                }

                return $this->page([]);
            });

        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $publishedPayloads = [];
        $this->hub->method('publish')
            ->willReturnCallback(function (Update $update) use (&$publishedPayloads) {
                $publishedPayloads[] = json_decode((string) $update->getData(), true);

                return 'id';
            });

        $this->service->syncCharacterTransactions($character);

        $statuses = array_column($publishedPayloads, 'status');
        $this->assertContains('in_progress', $statuses);

        // Find the progress update and verify its message
        $progressUpdates = array_filter($publishedPayloads, fn ($p) => $p['status'] === 'in_progress');
        $progressUpdate = reset($progressUpdates);
        $this->assertStringContainsString('2 transactions fetched', $progressUpdate['message']);
    }

    // ===========================================
    // ESI error handling
    // ===========================================

    public function testEsiErrorPublishesSyncErrorAndReturns(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')
            ->willThrowException(new \RuntimeException('ESI timeout'));

        $publishedPayloads = [];
        $this->hub->method('publish')
            ->willReturnCallback(function (Update $update) use (&$publishedPayloads) {
                $publishedPayloads[] = json_decode((string) $update->getData(), true);

                return 'id';
            });

        $this->service->syncCharacterTransactions($character);

        $statuses = array_column($publishedPayloads, 'status');
        $this->assertContains('error', $statuses);
        $this->assertNotContains('completed', $statuses);

        // Find the error update and verify message
        $errorUpdates = array_filter($publishedPayloads, fn ($p) => $p['status'] === 'error');
        $errorUpdate = reset($errorUpdates);
        $this->assertSame('ESI timeout', $errorUpdate['message']);
    }

    // ===========================================
    // No Mercure when user is null
    // ===========================================

    public function testNoMercureWhenUserIdIsNull(): void
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);

        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getEveCharacterId')->willReturn(12345);
        $character->method('getName')->willReturn('TestChar');
        $character->method('getUser')->willReturn(null);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([]));

        // Hub should never be called when user is null
        $this->hub->expects($this->never())->method('publish');

        $this->service->syncCharacterTransactions($character);
    }

    #[Group('issue-916')]
    public function testSuccessfulReadRecordsReadAtAndTheExpiresOfEsiAsNextReadAt(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([$this->makeTransactionData(8001), $this->makeTransactionData(8002)], '21:04:00'),
            $this->page([], '21:04:00'),
        );
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->service->syncCharacterTransactions($character);

        $this->assertCount(1, $this->recordedReadings);
        $this->assertSame($character, $this->recordedReadings[0]['character']);
        $this->assertSame([['2026-11-13 20:04:10', '2026-11-13 21:04:00']], $this->recordedDates());
    }

    #[Group('issue-916')]
    public function testReadingIsRecordedThroughTheRepositoryUpsertAndNeverLoadedNorPersistedAsAnEntity(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([], '21:04:00'));
        $this->readingRepository->expects($this->never())->method('findOneByCharacter');

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([['2026-11-13 20:04:10', '2026-11-13 21:04:00']], $this->recordedDates());
        $this->assertSame([], $this->persistedReadings());
    }

    #[Group('issue-916')]
    public function testReadOfZeroTransactionsStillRecordsTheReading(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([], '21:04:00'));

        $this->purchaseLinker->expects($this->never())->method('linkNewPurchases');

        $this->service->syncCharacterTransactions($character);

        $this->assertCount(1, $this->recordedReadings);
        $this->assertSame($character, $this->recordedReadings[0]['character']);
        $this->assertSame([['2026-11-13 20:04:10', '2026-11-13 21:04:00']], $this->recordedDates());
    }

    /** @return iterable<string, array{string, string}> */
    public static function expiresOfTwoPagesInReadingOrder(): iterable
    {
        yield 'earliest expires on the second page' => ['21:04:00', '21:03:30'];
        yield 'earliest expires on the first page' => ['21:03:30', '21:04:00'];
    }

    #[Group('issue-916')]
    #[DataProvider('expiresOfTwoPagesInReadingOrder')]
    public function testNextReadAtIsTheEarliestExpiresOfThePagesRead(string $firstPageExpires, string $secondPageExpires): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->expects($this->exactly(2))->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([$this->makeTransactionData(8101), $this->makeTransactionData(8102)], $firstPageExpires),
            $this->page([$this->makeTransactionData(8103)], $secondPageExpires),
        );
        $this->transactionRepository->method('findExistingTransactionIds')->willReturnCallback(
            static fn (array $ids): array => in_array(8103, $ids, true) ? [8103] : [],
        );

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([['2026-11-13 20:04:10', '2026-11-13 21:03:30']], $this->recordedDates());
    }

    #[Group('issue-916')]
    public function testNextReadAtIsOneHourAfterTheReadWhenEsiSendsNoExpires(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([]));

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([['2026-11-13 20:04:10', '2026-11-13 21:04:10']], $this->recordedDates());
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function expiresOfTwoPagesWithOneMissing(): iterable
    {
        yield 'second page without expires' => ['21:03:30', null];
        yield 'first page without expires' => [null, '21:03:30'];
    }

    #[Group('issue-916')]
    #[DataProvider('expiresOfTwoPagesWithOneMissing')]
    public function testNextReadAtFallsBackToOneHourAfterTheReadWhenOnePageHasNoExpires(?string $firstPageExpires, ?string $secondPageExpires): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->expects($this->exactly(2))->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([$this->makeTransactionData(8111), $this->makeTransactionData(8112)], $firstPageExpires),
            $this->page([$this->makeTransactionData(8113)], $secondPageExpires),
        );
        $this->transactionRepository->method('findExistingTransactionIds')->willReturnCallback(
            static fn (array $ids): array => in_array(8113, $ids, true) ? [8113] : [],
        );

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([['2026-11-13 20:04:10', '2026-11-13 21:04:10']], $this->recordedDates());
    }

    #[Group('issue-916')]
    public function testFailedReadRecordsNoReadAtAndRetriesFifteenMinutesLater(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willThrowException(new \RuntimeException('ESI timeout'));

        $this->service->syncCharacterTransactions($character);

        $this->assertCount(1, $this->recordedReadings);
        $this->assertSame($character, $this->recordedReadings[0]['character']);
        $this->assertSame([[null, '2026-11-13 20:19:10']], $this->recordedDates());
        $this->assertSame([], $this->persistedReadings());
    }

    #[Group('issue-916')]
    public function testFailureOnTheSecondPageDoesNotRecordTheFirstPageAsASuccessfulRead(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([$this->makeTransactionData(8201), $this->makeTransactionData(8202)], '21:04:00'),
            $this->throwException(new \RuntimeException('ESI timeout')),
        );
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->service->syncCharacterTransactions($character);

        $this->assertSame([[null, '2026-11-13 20:19:10']], $this->recordedDates());
    }

    #[Group('issue-916')]
    public function testFailureToRecordASuccessfulReadPublishesSyncErrorAndNeverSyncCompleted(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturn($this->page([], '21:04:00'));
        $this->databaseGoesDown();
        $publishedPayloads = $this->capturePublishedPayloads();

        $this->runIgnoringAnyFailure($character);

        $statuses = array_column($publishedPayloads->getArrayCopy(), 'status');
        $this->assertContains('error', $statuses);
        $this->assertNotContains('completed', $statuses);
        $errorMessages = array_column(array_filter(
            $publishedPayloads->getArrayCopy(),
            static fn (array $payload): bool => $payload['status'] === 'error',
        ), 'message');
        $this->assertSame(['database down'], array_values($errorMessages));
    }

    #[Group('issue-916')]
    public function testFailureToRecordTheFailedReadKeepsTheOriginalEsiErrorAndPublishesSyncError(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willThrowException(new \RuntimeException('ESI timeout'));
        $this->databaseGoesDown();
        $publishedPayloads = $this->capturePublishedPayloads();

        $propagated = $this->runIgnoringAnyFailure($character);

        $errorMessages = array_column(array_filter(
            $publishedPayloads->getArrayCopy(),
            static fn (array $payload): bool => $payload['status'] === 'error',
        ), 'message');
        $this->assertSame(['ESI timeout'], array_values($errorMessages));
        $this->assertNotContains('completed', array_column($publishedPayloads->getArrayCopy(), 'status'));
        $this->assertTrue($propagated === null || $propagated->getMessage() === 'ESI timeout');
    }

    #[Group('issue-916')]
    public function testNewPurchasesAreLinkedBeforeSyncCompletedIsPublished(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('getWithExpiry')->willReturnOnConsecutiveCalls(
            $this->page([$this->makeTransactionData(8301), $this->makeTransactionData(8302)], '21:04:00'),
            $this->page([], '21:04:00'),
        );
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $events = [];
        $this->hub->method('publish')->willReturnCallback(
            static function (Update $update) use (&$events): string {
                $events[] = 'mercure:' . json_decode((string) $update->getData(), true)['status'];

                return 'id';
            },
        );
        $this->purchaseLinker->method('linkNewPurchases')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'link';
            },
        );

        $this->service->syncCharacterTransactions($character);

        $this->assertSame(['mercure:started', 'mercure:in_progress', 'link', 'mercure:completed'], $events);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function page(array $transactions, ?string $expiresAtUtc = null): PaginatedEsiResponse
    {
        return new PaginatedEsiResponse(
            $transactions,
            $expiresAtUtc === null ? null : new \DateTimeImmutable('2026-11-13T' . $expiresAtUtc . '+00:00'),
        );
    }

    private function persistedReadings(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $entity): bool => $entity instanceof CharacterWalletReading,
        ));
    }

    private function persistedTransactionIds(): array
    {
        return array_values(array_map(
            static fn (CachedWalletTransaction $transaction): int => $transaction->getTransactionId(),
            array_filter(
                $this->persisted,
                static fn (object $entity): bool => $entity instanceof CachedWalletTransaction,
            ),
        ));
    }

    /**
     * @return list<array{?string, string}>
     */
    private function recordedDates(): array
    {
        $utc = new \DateTimeZone('UTC');

        return array_map(
            static fn (array $reading): array => [
                $reading['readAt']?->setTimezone($utc)->format('Y-m-d H:i:s'),
                $reading['nextReadAt']->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
            $this->recordedReadings,
        );
    }

    private function databaseGoesDown(): void
    {
        $this->recordFailure = new \RuntimeException('database down');
        $this->em->method('flush')->willThrowException($this->recordFailure);
    }

    private function capturePublishedPayloads(): \ArrayObject
    {
        $publishedPayloads = new \ArrayObject();
        $this->hub->method('publish')->willReturnCallback(
            static function (Update $update) use ($publishedPayloads): string {
                $publishedPayloads->append(json_decode((string) $update->getData(), true));

                return 'id';
            },
        );

        return $publishedPayloads;
    }

    private function runIgnoringAnyFailure(Character $character): ?\Throwable
    {
        try {
            $this->service->syncCharacterTransactions($character);
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function createCharacterWithUser(int $eveCharacterId): Character
    {
        $user = $this->createStub(User::class);
        $userId = Uuid::v4();
        $user->method('getId')->willReturn($userId);

        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');
        $character->method('getUser')->willReturn($user);

        return $character;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeTransactionData(int $transactionId): array
    {
        return [
            'transaction_id' => $transactionId,
            'type_id' => 34, // Tritanium
            'quantity' => 1000,
            'unit_price' => 5.50,
            'is_buy' => true,
            'location_id' => 60003760,
            'client_id' => 98000001,
            'date' => (new \DateTimeImmutable('-1 hour'))->format('c'),
        ];
    }
}
