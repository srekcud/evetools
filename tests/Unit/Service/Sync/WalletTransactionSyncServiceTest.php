<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\WalletTransactionSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    private WalletTransactionSyncService $service;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->transactionRepository = $this->createMock(CachedWalletTransactionRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $mercurePublisher = new MercurePublisherService($this->hub, new NullLogger());

        $this->service = new WalletTransactionSyncService(
            $this->esiClient,
            $this->transactionRepository,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
        );
    }

    // ===========================================
    // Early returns -- no token or no scope
    // ===========================================

    public function testNoTokenReturnsEarly(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);

        $this->esiClient->expects($this->never())->method('get');
        $this->em->expects($this->never())->method('flush');

        $this->service->syncCharacterTransactions($character);
    }

    public function testNoScopeReturnsEarly(): void
    {
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(false);

        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);

        $this->esiClient->expects($this->never())->method('get');
        $this->em->expects($this->never())->method('flush');

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // New transaction creation
    // ===========================================

    public function testNewTransactionsArePersisted(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturnOnConsecutiveCalls(
            [
                $this->makeTransactionData(1001),
                $this->makeTransactionData(1002),
            ],
            [], // second page empty, stops pagination
        );

        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->em->expects($this->exactly(2))->method('persist');

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // Deduplication -- existing transactions skipped
    // ===========================================

    public function testExistingTransactionsAreSkipped(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturn([
            $this->makeTransactionData(2001),
            $this->makeTransactionData(2002),
        ]);

        // Both already exist
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([2001, 2002]);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterTransactions($character);
    }

    public function testMixOfNewAndExistingTransactions(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturnOnConsecutiveCalls(
            [
                $this->makeTransactionData(3001),
                $this->makeTransactionData(3002),
                $this->makeTransactionData(3003),
            ],
            [], // second page empty, stops pagination
        );

        // Only 3001 exists
        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([3001]);

        $this->em->expects($this->exactly(2))->method('persist');

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // Pagination -- from_id logic
    // ===========================================

    public function testPaginationStopsWhenEsiReturnsEmptyPage(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $url) {
                if (!str_contains($url, 'from_id')) {
                    return [
                        $this->makeTransactionData(5001),
                        $this->makeTransactionData(5002),
                    ];
                }

                return [];
            });

        $this->transactionRepository->method('findExistingTransactionIds')->willReturn([]);

        $this->service->syncCharacterTransactions($character);
    }

    public function testPaginationUsesLowestIdAsFromId(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $callCount = 0;
        $this->esiClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $url) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertStringNotContainsString('from_id', $url);

                    return [
                        $this->makeTransactionData(200),
                        $this->makeTransactionData(100), // lowest ID
                        $this->makeTransactionData(300),
                    ];
                }

                // Second call should use from_id=100 (the lowest)
                $this->assertStringContainsString('from_id=100', $url);

                return [];
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
            ->method('get')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return [
                        $this->makeTransactionData(6001),
                        $this->makeTransactionData(6002),
                    ];
                }

                // Second page: all transactions already exist
                return [
                    $this->makeTransactionData(6003),
                    $this->makeTransactionData(6004),
                ];
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

        // Only 2 persists (from first page), second page is all duplicates
        $this->em->expects($this->exactly(2))->method('persist');

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // Mercure notifications
    // ===========================================

    public function testMercureSyncStartedAndCompletedPublished(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturn([]);

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
        $this->esiClient->method('get')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return [
                        $this->makeTransactionData(7001),
                        $this->makeTransactionData(7002),
                    ];
                }

                return [];
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

        $this->esiClient->method('get')
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

        $this->esiClient->method('get')->willReturn([]);

        // Hub should never be called when user is null
        $this->hub->expects($this->never())->method('publish');

        $this->service->syncCharacterTransactions($character);
    }

    // ===========================================
    // Helpers
    // ===========================================

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
