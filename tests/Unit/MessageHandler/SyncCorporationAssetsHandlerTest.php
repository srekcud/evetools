<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EveAuthRequiredException;
use App\Message\SyncCorporationAssets;
use App\MessageHandler\SyncCorporationAssetsHandler;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\AssetsSyncService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SyncCorporationAssetsHandler::class)]
final class SyncCorporationAssetsHandlerTest extends TestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const string CHARACTER_UUID = '0199a1f0-0000-7000-8000-000000000001';
    private const string USER_UUID = '0199a1f0-0000-7000-8000-0000000000a1';
    private const string REQUESTER_UUID = '0199a1f0-0000-7000-8000-0000000000b2';
    private const string ASSETS_SYNC_TYPE = 'corporation-assets';
    private const string CHARACTER_ASSETS_SYNC_TYPE = 'assets';
    private const string CORPORATION_ASSETS_TOPIC = '/user/' . self::USER_UUID . '/sync/corporation-assets';

    /** @var list<Update> */
    private array $publishedUpdates = [];
    private MercurePublisherService $mercurePublisher;
    private SyncTracker $syncTracker;

    protected function setUp(): void
    {
        $this->publishedUpdates = [];
        $this->mercurePublisher = new MercurePublisherService($this->hubCollectingUpdates(), new NullLogger());
        $this->syncTracker = new SyncTracker(new ArrayAdapter(), $this->mercurePublisher);
    }

    public function testCharacterThatCannotSyncPublishesAnErrorOnTheUserCorporationAssetsTopic(): void
    {
        $assetsSyncService = $this->createMock(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(false);
        $assetsSyncService->expects(self::never())->method('syncCorporationAssets');

        $this->handle($this->characterWithUser(), $assetsSyncService);

        $errors = $this->errorPayloadsOn(self::CORPORATION_ASSETS_TOPIC);
        self::assertCount(1, $errors);
        self::assertNotSame('', $errors[0]['message']);
    }

    public function testCharacterThatCannotSyncIsRecordedAsAFailedAssetsSync(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(false);

        $this->handle($this->characterWithUser(), $assetsSyncService);

        $entry = $this->trackedAssetsEntry();
        self::assertSame('error', $entry['status']);
        self::assertNotSame('', $entry['message']);
    }

    public function testMissingTriggerCharacterIsRecordedAsAFailedAssetsSync(): void
    {
        $assetsSyncService = $this->createMock(AssetsSyncService::class);
        $assetsSyncService->expects(self::never())->method('syncCorporationAssets');

        $this->handle(null, $assetsSyncService);

        $entry = $this->trackedAssetsEntry();
        self::assertSame('error', $entry['status']);
        self::assertNotSame('', $entry['message']);
    }

    public function testRevokedEveGrantDuringSyncIsRecordedAsAFailedAssetsSyncWithoutRetry(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(true);
        $assetsSyncService->method('syncCorporationAssets')->willThrowException(new EveAuthRequiredException('2112000001'));

        $this->handle($this->characterWithUser(), $assetsSyncService);

        self::assertSame('error', $this->trackedAssetsEntry()['status']);
    }

    public function testUnexpectedFailureIsRecordedAsAFailedAssetsSyncAndStillRethrownForRetry(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(true);
        $assetsSyncService->method('syncCorporationAssets')->willThrowException(new \RuntimeException('ESI server error'));

        $this->expectException(\RuntimeException::class);

        try {
            $this->handle($this->characterWithUser(), $assetsSyncService);
        } finally {
            $entry = $this->trackedAssetsEntry();
            self::assertSame('error', $entry['status']);
            self::assertSame('ESI server error', $entry['message']);
        }
    }

    public function testSuccessfulSyncPublishesNoErrorAndRecordsNoFailure(): void
    {
        $assetsSyncService = $this->createMock(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(true);
        $assetsSyncService->expects(self::once())->method('syncCorporationAssets');

        $this->handle($this->characterWithUser(), $assetsSyncService);

        self::assertSame([], $this->errorPayloadsOn(self::CORPORATION_ASSETS_TOPIC));
        self::assertNotSame('error', $this->trackedAssetsEntry()['status']);
    }

    public function testSuccessfulSyncIsRecordedAsACompletedCorporationAssetsSync(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(true);

        $this->handle($this->characterWithUser(), $assetsSyncService);

        $entry = $this->trackedAssetsEntry();
        self::assertSame('ok', $entry['status']);
        self::assertNotNull($entry['completed_at']);
    }

    public function testCorporationAssetsSyncIsRecordedAsRunningWhileItSyncs(): void
    {
        $statusDuringSync = null;
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(true);
        $assetsSyncService->method('syncCorporationAssets')->willReturnCallback(function () use (&$statusDuringSync): void {
            $statusDuringSync = $this->trackedAssetsEntry()['status'];
        });

        $this->handle($this->characterWithUser(), $assetsSyncService);

        self::assertSame('running', $statusDuringSync);
    }

    public function testCorporationAssetsSyncDoesNotTouchTheCharacterAssetsRow(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(false);

        $this->handle($this->characterWithUser(), $assetsSyncService);

        foreach ($this->syncTracker->getAll() as $entry) {
            if ($entry['type'] === self::CHARACTER_ASSETS_SYNC_TYPE) {
                self::assertSame('unknown', $entry['status']);

                return;
            }
        }
        self::fail('assets sync type is not tracked');
    }

    public function testSyncIsRunForTheRequestingUser(): void
    {
        $character = $this->characterWithUser();
        $assetsSyncService = $this->createMock(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(true);
        $assetsSyncService->expects(self::once())->method('syncCorporationAssets')->with($character, self::REQUESTER_UUID);

        $this->handle($character, $assetsSyncService, self::REQUESTER_UUID);
    }

    public function testCharacterThatCannotSyncPublishesTheErrorToTheRequestingUser(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(false);

        $this->handle($this->characterWithUser(), $assetsSyncService, self::REQUESTER_UUID);

        self::assertCount(1, $this->errorPayloadsOn('/user/' . self::REQUESTER_UUID . '/sync/corporation-assets'));
        self::assertCount(1, $this->errorPayloadsOn(self::CORPORATION_ASSETS_TOPIC));
    }

    public function testRequesterWhoOwnsTheTriggerCharacterGetsTheErrorOnlyOnce(): void
    {
        $assetsSyncService = $this->createStub(AssetsSyncService::class);
        $assetsSyncService->method('canSync')->willReturn(false);

        $this->handle($this->characterWithUser(), $assetsSyncService, self::USER_UUID);

        self::assertCount(1, $this->errorPayloadsOn(self::CORPORATION_ASSETS_TOPIC));
    }

    private function handle(?Character $character, AssetsSyncService $assetsSyncService, string $requestedByUserId = self::USER_UUID): void
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('find')->willReturn($character);

        $handler = new SyncCorporationAssetsHandler(
            $characterRepository,
            $assetsSyncService,
            $this->mercurePublisher,
            $this->syncTracker,
            new NullLogger(),
        );

        $handler(new SyncCorporationAssets(self::CORPORATION_ID, self::CHARACTER_UUID, $requestedByUserId));
    }

    private function hubCollectingUpdates(): HubInterface
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'id';
        });

        return $hub;
    }

    private function characterWithUser(): Character
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, Uuid::fromString(self::USER_UUID));
        $character = (new Character())->setEveCharacterId(2_112_000_001)->setName('Director Pilot');
        $character->setEveToken(new EveToken());
        $user->addCharacter($character);

        return $character;
    }

    /** @return list<array<string, mixed>> */
    private function errorPayloadsOn(string $topic): array
    {
        $payloads = [];
        foreach ($this->publishedUpdates as $update) {
            if (!\in_array($topic, $update->getTopics(), true)) {
                continue;
            }
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            if ($payload['status'] === 'error') {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /** @return array<string, mixed> */
    private function trackedAssetsEntry(): array
    {
        foreach ($this->syncTracker->getAll() as $entry) {
            if ($entry['type'] === self::ASSETS_SYNC_TYPE) {
                return $entry;
            }
        }

        self::fail('assets sync type is not tracked');
    }
}
