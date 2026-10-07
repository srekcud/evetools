<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Pve\PveSyncResource;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\PveSyncService;
use App\State\Processor\Pve\SyncPveProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/pve/sync: the response status must reflect how many characters failed (issue #79).
 * The real PveSyncService is wired; only ESI, Mercure, repositories and Doctrine are stubbed.
 */
#[CoversClass(SyncPveProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class SyncPveProcessorTest extends TestCase
{
    private const OK_CHARACTER_ID = 11111;
    private const FAILING_CHARACTER_ID = 22222;
    private const PREVIOUS_PVE_SYNC_AT = '2026-01-01 00:00:00';

    private Security&Stub $security;
    private EsiClient&Stub $esiClient;
    private UserPveSettingsRepository&Stub $settingsRepository;
    private SyncPveProcessor $processor;

    /** @var list<array<string, mixed>> Mercure sync payloads, in publication order */
    private array $publishedSyncEvents = [];

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->settingsRepository = $this->createStub(UserPveSettingsRepository::class);

        $this->publishedSyncEvents = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedSyncEvents[] = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
            return 'urn:uuid:test';
        });

        $pveSyncService = new PveSyncService(
            $this->esiClient,
            $this->createStub(TokenManager::class),
            $this->createStub(PveIncomeRepository::class),
            $this->createStub(PveExpenseRepository::class),
            $this->settingsRepository,
            $this->createStub(InvTypeRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new MercurePublisherService($hub, new NullLogger()),
        );

        $this->processor = new SyncPveProcessor($this->security, $pveSyncService, $this->settingsRepository);

        $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);
    }

    public function testSyncReportsErrorWhenEveryCharacterFails(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithCharacters(self::OK_CHARACTER_ID, self::FAILING_CHARACTER_ID));
        $this->esiClient->method('get')->willThrowException(new \RuntimeException('ESI 502 Bad Gateway'));
        $this->esiClient->method('getPaginated')->willThrowException(new \RuntimeException('ESI 502 Bad Gateway'));

        $resource = $this->sync();

        $this->assertSame('error', $this->lastSyncEvent()['status']);
        $this->assertSame('error', $resource->status);
        $this->assertSame('Sync failed for all 2 characters', $resource->message);
        $this->assertSame((new \DateTimeImmutable(self::PREVIOUS_PVE_SYNC_AT))->format('c'), $resource->lastSyncAt);
    }

    public function testSyncReportsPartialWhenOneOfTwoCharactersFails(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithCharacters(self::OK_CHARACTER_ID, self::FAILING_CHARACTER_ID));
        $this->stubEsiFailingFor(self::FAILING_CHARACTER_ID);

        $resource = $this->sync();

        $this->assertSame('completed', $this->lastSyncEvent()['status']);
        $this->assertSame('1 bounties, 0 sales, 0 contracts, 0 expenses (1 of 2 characters failed)', $this->lastSyncEvent()['message']);
        $this->assertSame('partial', $resource->status);
        $this->assertSame('Synced 1 bounties, 0 loot sales, 0 loot contracts, 0 expenses (1 of 2 characters failed)', $resource->message);
        $this->assertSame(1, $resource->imported['bounties']);
    }

    public function testSyncReportsSuccessWhenEveryCharacterSucceeds(): void
    {
        $this->security->method('getUser')->willReturn($this->userWithCharacters(self::OK_CHARACTER_ID));
        $this->stubEsiFailingFor(null);

        $resource = $this->sync();

        $this->assertSame('completed', $this->lastSyncEvent()['status']);
        $this->assertSame('success', $resource->status);
        $this->assertSame('Synced 1 bounties, 0 loot sales, 0 loot contracts, 0 expenses', $resource->message);
        $this->assertSame(1, $resource->imported['bounties']);
        $this->assertSame([], $resource->errors);
    }

    private function sync(): PveSyncResource
    {
        return $this->processor->process(null, new Post());
    }

    private function userWithCharacters(int ...$eveCharacterIds): User
    {
        $characters = [];
        foreach ($eveCharacterIds as $eveCharacterId) {
            $token = $this->createStub(EveToken::class);
            $token->method('isExpiringSoon')->willReturn(false);

            $character = $this->createStub(Character::class);
            $character->method('getEveCharacterId')->willReturn($eveCharacterId);
            $character->method('getEveToken')->willReturn($token);
            $character->method('getName')->willReturn('Char ' . $eveCharacterId);
            $characters[] = $character;
        }

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getCharacters')->willReturn(new ArrayCollection($characters));

        return $user;
    }

    /** Settings with loot and ammo types, so that every sync step calls ESI for every character */
    private function settingsSyncedAt(string $lastSyncAt): void
    {
        $settings = new UserPveSettings();
        $settings->setLootTypeIds([UserPveSettings::PVE_LOOT_TYPE_IDS[0]]);
        $settings->setAmmoTypeIds([21898]);
        $settings->setLastSyncAt(new \DateTimeImmutable($lastSyncAt));
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);
    }

    /** Every endpoint of $failingEveCharacterId throws; the others return one bounty and nothing else */
    private function stubEsiFailingFor(?int $failingEveCharacterId): void
    {
        $respond = function (string $endpoint) use ($failingEveCharacterId): array {
            if ($failingEveCharacterId !== null && str_contains($endpoint, "/characters/{$failingEveCharacterId}/")) {
                throw new \RuntimeException('ESI 502 Bad Gateway');
            }
            if (str_ends_with($endpoint, '/wallet/journal/')) {
                return [[
                    'id' => 600001,
                    'ref_type' => 'bounty_prizes',
                    'amount' => 5_000_000.0,
                    'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
                ]];
            }
            return [];
        };
        $this->esiClient->method('get')->willReturnCallback($respond);
        $this->esiClient->method('getPaginated')->willReturnCallback($respond);
    }

    /** @return array<string, mixed> */
    private function lastSyncEvent(): array
    {
        $this->assertNotEmpty($this->publishedSyncEvents);

        return $this->publishedSyncEvents[array_key_last($this->publishedSyncEvents)];
    }
}
