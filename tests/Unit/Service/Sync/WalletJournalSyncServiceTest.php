<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\CachedWalletJournalEntry;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\WalletJournalSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class WalletJournalSyncServiceTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2_112_500_001;
    private const int SENDER_CHARACTER_ID = 2_112_500_777;
    private const string WALLET_SCOPE = 'esi-wallet.read_character_wallet.v1';

    private EsiClient&MockObject $esiClient;
    private CachedWalletJournalEntryRepository&MockObject $journalRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private WalletJournalSyncService $service;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->journalRepository = $this->createMock(CachedWalletJournalEntryRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->service = new WalletJournalSyncService(
            $this->esiClient,
            $this->journalRepository,
            $this->entityManager,
            new NullLogger(),
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testCharacterWithoutTheWalletScopeIsNotRead(): void
    {
        $this->esiClient->expects(self::never())->method('getPaginated');

        $this->service->syncCharacterJournal($this->characterWithScopes(['esi-assets.read_assets.v1']));
    }

    public function testCharacterWithoutTokenIsNotRead(): void
    {
        $this->esiClient->expects(self::never())->method('getPaginated');

        $this->service->syncCharacterJournal($this->characterWithToken(null));
    }

    public function testOnlyTheContractFeeLinesAreKeptWithTheirFields(): void
    {
        $this->esiClient->method('getPaginated')
            ->with('/characters/'.self::EVE_CHARACTER_ID.'/wallet/journal/')
            ->willReturn([
                $this->line(9_001, 'contract_brokers_fee', -30_000.0, 601_001),
                $this->line(9_002, 'player_trading', 5_000_000.0, null),
                $this->line(9_003, 'contract_sales_tax', -108_000.5, 601_001),
                $this->line(9_004, 'market_transaction', -12.0, 77),
            ]);
        $this->journalRepository->method('findExistingEntryIds')->willReturn([]);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });
        $character = $this->characterWithScopes([self::WALLET_SCOPE]);

        $this->service->syncCharacterJournal($character);

        self::assertSame(
            [
                [9_001, 'contract_brokers_fee', -30_000.0, 601_001, 'contract_id'],
                [9_003, 'contract_sales_tax', -108_000.5, 601_001, 'contract_id'],
            ],
            array_map(static fn (CachedWalletJournalEntry $entry): array => [
                $entry->getJournalEntryId(),
                $entry->getRefType(),
                $entry->getAmount(),
                $entry->getContextId(),
                $entry->getContextIdType(),
            ], $persisted),
        );
        self::assertSame($character, $persisted[0]->getCharacter());
    }

    public function testOnlyTheIncomingDonationsCarryingAProjectPaymentReferenceAreKept(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([
            $this->donationLine(9_101, 277_500.0, 'IP-4C81D2 thanks', self::SENDER_CHARACTER_ID),
            $this->donationLine(9_102, 50_000.0, 'for the beers', self::SENDER_CHARACTER_ID),
            $this->donationLine(9_103, 80_000.0, null, self::SENDER_CHARACTER_ID),
            $this->donationLine(9_104, -90_000.0, 'IP-4C81D2', self::EVE_CHARACTER_ID),
            $this->donationLine(9_105, 12_000.0, 'ip-9f00a1 batch', self::SENDER_CHARACTER_ID),
            $this->line(9_106, 'player_trading', 5_000_000.0, null),
        ]);
        $this->journalRepository->method('findExistingEntryIds')->willReturn([]);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });

        $this->service->syncCharacterJournal($this->characterWithScopes([self::WALLET_SCOPE]));

        self::assertSame(
            [
                [9_101, 'player_donation', 277_500.0, 'IP-4C81D2 thanks', self::SENDER_CHARACTER_ID],
                [9_105, 'player_donation', 12_000.0, 'ip-9f00a1 batch', self::SENDER_CHARACTER_ID],
            ],
            array_map(static fn (CachedWalletJournalEntry $entry): array => [
                $entry->getJournalEntryId(),
                $entry->getRefType(),
                $entry->getAmount(),
                $entry->getReason(),
                $entry->getFirstPartyId(),
            ], $persisted),
        );
    }

    public function testContractFeeLinesKeepNoReasonNorSender(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([$this->line(9_001, 'contract_brokers_fee', -30_000.0, 601_001)]);
        $this->journalRepository->method('findExistingEntryIds')->willReturn([]);
        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (object $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });

        $this->service->syncCharacterJournal($this->characterWithScopes([self::WALLET_SCOPE]));

        self::assertSame([null, null], [$persisted[0]->getReason(), $persisted[0]->getFirstPartyId()]);
    }

    public function testAnEntryAlreadyStoredIsNotStoredAgain(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([
            $this->line(9_001, 'contract_brokers_fee', -30_000.0, 601_001),
            $this->line(9_003, 'contract_sales_tax', -108_000.0, 601_001),
        ]);
        $this->journalRepository->method('findExistingEntryIds')->willReturn([9_001]);
        $persistedIds = [];
        $this->entityManager->method('persist')->willReturnCallback(static function (CachedWalletJournalEntry $entry) use (&$persistedIds): void {
            $persistedIds[] = $entry->getJournalEntryId();
        });

        $this->service->syncCharacterJournal($this->characterWithScopes([self::WALLET_SCOPE]));

        self::assertSame([9_003], $persistedIds);
    }

    public function testEsiFailureIsPublishedAsASyncErrorAndDoesNotBreakTheCaller(): void
    {
        $this->esiClient->method('getPaginated')->willThrowException(new EsiApiException('ESI down', 503));
        $published = [];
        $this->hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $this->service->syncCharacterJournal($this->characterWithScopes([self::WALLET_SCOPE]));

        self::assertSame(['started', 'error'], array_column($published, 'status'));
        self::assertSame('wallet-journal', $published[0]['syncType']);
    }

    public function testFlushFailureIsPublishedAsASyncError(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([$this->line(9_001, 'contract_brokers_fee', -30_000.0, 601_001)]);
        $this->journalRepository->method('findExistingEntryIds')->willReturn([]);
        $this->entityManager->method('flush')->willThrowException(new \RuntimeException('database gone'));
        $published = [];
        $this->hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $this->service->syncCharacterJournal($this->characterWithScopes([self::WALLET_SCOPE]));

        self::assertSame(['started', 'error'], array_column($published, 'status'));
    }

    public function testCompletedSyncIsPublished(): void
    {
        $this->esiClient->method('getPaginated')->willReturn([]);
        $published = [];
        $this->hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = json_decode($update->getData(), true);

            return 'id';
        });

        $this->service->syncCharacterJournal($this->characterWithScopes([self::WALLET_SCOPE]));

        self::assertSame('completed', end($published)['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $id, string $refType, float $amount, ?int $contextId): array
    {
        return [
            'id' => $id,
            'ref_type' => $refType,
            'amount' => $amount,
            'date' => '2026-11-09T18:00:00Z',
            'context_id' => $contextId,
            'context_id_type' => $contextId === null ? null : 'contract_id',
            'balance' => 1_000.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function donationLine(int $id, float $amount, ?string $reason, int $firstPartyId): array
    {
        $line = [
            'id' => $id,
            'ref_type' => 'player_donation',
            'amount' => $amount,
            'date' => '2026-11-10T18:00:00Z',
            'first_party_id' => $firstPartyId,
            'second_party_id' => self::EVE_CHARACTER_ID,
            'balance' => 1_000.0,
        ];

        if ($reason !== null) {
            $line['reason'] = $reason;
        }

        return $line;
    }

    /**
     * @param list<string> $scopes
     */
    private function characterWithScopes(array $scopes): Character
    {
        return $this->characterWithToken((new EveToken())->setScopes($scopes));
    }

    private function characterWithToken(?EveToken $token): Character
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('Aria Solberg');
        $character->method('getUser')->willReturn($user);

        return $character;
    }
}
