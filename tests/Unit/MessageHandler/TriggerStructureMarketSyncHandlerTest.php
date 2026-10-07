<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Message\TriggerStructureMarketSync;
use App\MessageHandler\TriggerStructureMarketSyncHandler;
use App\Repository\CharacterRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

/**
 * Issue #28: the scheduled structure market sync used an arbitrary character (first one with a token,
 * whatever its scopes), marked the tracker complete before any sync ran and sent Mercure events to
 * the user owning that arbitrary character.
 *
 * Expected contract (not implemented yet):
 * - the handler syncs each structure itself through StructureMarketService (no fire-and-forget dispatch),
 *   so the tracker reflects the actual results;
 * - the character of each structure comes from
 *   CharacterRepository::findStructureMarketRequester(int $structureId, bool $isDefaultStructure): ?Character
 *   (cf. tests/Integration/Repository/CharacterRepositoryStructureMarketRequesterTest).
 */
#[CoversClass(TriggerStructureMarketSyncHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class TriggerStructureMarketSyncHandlerTest extends TestCase
{
    use StructureMarketSyncFixtures;

    private const int DEFAULT_STRUCTURE_ID = 1_035_466_617_946;
    private const string DEFAULT_STRUCTURE_NAME = '4-HWWF - WinterCo. Central Station';
    private const int PREFERRED_STRUCTURE_ID = 1_046_664_001_931;
    private const string SYNC_TYPE = 'market-structure';

    private EsiClient&MockObject $esiClient;
    private CharacterRepository&MockObject $characterRepository;
    private SyncTracker $syncTracker;
    private TriggerStructureMarketSyncHandler $handler;

    /** @var array<int, Character|null> structureId => requester returned by the repository */
    private array $requesters = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->characterRepository = $this->createMock(CharacterRepository::class);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findDistinctPreferredMarketStructureIds')
            ->willReturn([self::PREFERRED_STRUCTURE_ID]);

        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );

        $this->handler = new TriggerStructureMarketSyncHandler(
            characterRepository: $this->characterRepository,
            userRepository: $userRepository,
            structureMarketService: $this->createStructureMarketService($this->esiClient),
            logger: new NullLogger(),
            syncTracker: $this->syncTracker,
            defaultMarketStructureId: self::DEFAULT_STRUCTURE_ID,
            defaultMarketStructureName: self::DEFAULT_STRUCTURE_NAME,
        );
    }

    public function testSyncsEachStructureWithTheTokenOfItsOwnRequester(): void
    {
        $defaultRequester = $this->createPilot('Default Requester');
        $preferredRequester = $this->createPilot('Preferred Requester');
        $this->givenRequesters([
            self::DEFAULT_STRUCTURE_ID => $defaultRequester,
            self::PREFERRED_STRUCTURE_ID => $preferredRequester,
        ]);
        $esiCalls = $this->recordEsiCalls();

        ($this->handler)(new TriggerStructureMarketSync());

        self::assertSame([
            ['/markets/structures/' . self::DEFAULT_STRUCTURE_ID . '/', $defaultRequester->getEveToken()],
            ['/markets/structures/' . self::PREFERRED_STRUCTURE_ID . '/', $preferredRequester->getEveToken()],
        ], $esiCalls->getArrayCopy());
    }

    public function testLooksUpRequestersPerStructureIncludingUsersWithoutPreferenceForTheDefaultStructure(): void
    {
        $this->characterRepository->expects($this->exactly(2))
            ->method('findStructureMarketRequester')
            ->willReturnCallback(function (int $structureId, bool $isDefaultStructure): ?Character {
                self::assertSame($structureId === self::DEFAULT_STRUCTURE_ID, $isDefaultStructure);

                return null;
            });

        ($this->handler)(new TriggerStructureMarketSync());
    }

    public function testSendsMercureEventsToTheUserWhoRequestedEachStructure(): void
    {
        $defaultRequester = $this->createPilot('Default Requester');
        $preferredRequester = $this->createPilot('Preferred Requester');
        $this->givenRequesters([
            self::DEFAULT_STRUCTURE_ID => $defaultRequester,
            self::PREFERRED_STRUCTURE_ID => $preferredRequester,
        ]);
        $this->esiClient->method('getPaginated')->willReturn([]);

        ($this->handler)(new TriggerStructureMarketSync());

        // started, progress 50, progress 80, completed — per structure, to its own requester only
        self::assertSame([
            ...array_fill(0, 4, $this->marketStructureTopic($defaultRequester)),
            ...array_fill(0, 4, $this->marketStructureTopic($preferredRequester)),
        ], $this->publishedTopics);
    }

    public function testSkipsAStructureWithoutRequesterAndReportsItInsteadOfASilentSuccess(): void
    {
        $defaultRequester = $this->createPilot('Default Requester');
        $this->givenRequesters([
            self::DEFAULT_STRUCTURE_ID => $defaultRequester,
            self::PREFERRED_STRUCTURE_ID => null,
        ]);
        $esiCalls = $this->recordEsiCalls();

        ($this->handler)(new TriggerStructureMarketSync());

        self::assertSame(
            [['/markets/structures/' . self::DEFAULT_STRUCTURE_ID . '/', $defaultRequester->getEveToken()]],
            $esiCalls->getArrayCopy(),
        );
        $tracker = $this->trackerState();
        self::assertStringContainsString('skipped', $tracker['message']);
        self::assertStringContainsString((string) self::PREFERRED_STRUCTURE_ID, $tracker['message']);
    }

    public function testNeverFallsBackToAnArbitraryCharacterWithAValidToken(): void
    {
        $this->givenRequesters([
            self::DEFAULT_STRUCTURE_ID => null,
            self::PREFERRED_STRUCTURE_ID => null,
        ]);
        $this->characterRepository->method('findWithValidTokens')
            ->willReturn([$this->createPilot('Unrelated Pilot', ['esi-assets.read_assets.v1'])]);

        $this->esiClient->expects($this->never())->method('getPaginated');

        ($this->handler)(new TriggerStructureMarketSync());
    }

    public function testTrackerIsStillRunningWhileStructuresAreBeingSynced(): void
    {
        $this->givenRequesters([
            self::DEFAULT_STRUCTURE_ID => $this->createPilot('Default Requester'),
            self::PREFERRED_STRUCTURE_ID => $this->createPilot('Preferred Requester'),
        ]);
        $statusesDuringSync = [];
        $this->esiClient->method('getPaginated')
            ->willReturnCallback(function () use (&$statusesDuringSync): array {
                $statusesDuringSync[] = $this->trackerState()['status'];

                return [];
            });

        ($this->handler)(new TriggerStructureMarketSync());

        self::assertSame(['running', 'running'], $statusesDuringSync);
        self::assertSame('ok', $this->trackerState()['status']);
    }

    public function testTrackerReportsTheFailureWhenOneStructureSyncFails(): void
    {
        $this->givenRequesters([
            self::DEFAULT_STRUCTURE_ID => $this->createPilot('Default Requester'),
            self::PREFERRED_STRUCTURE_ID => $this->createPilot('Preferred Requester'),
        ]);
        $esiCalls = new \ArrayObject();
        $this->esiClient->method('getPaginated')
            ->willReturnCallback(function (string $endpoint, ?EveToken $token) use ($esiCalls): array {
                $esiCalls[] = $endpoint;
                if ($endpoint === '/markets/structures/' . self::PREFERRED_STRUCTURE_ID . '/') {
                    throw EsiApiException::forbidden(endpoint: $endpoint);
                }

                return [];
            });

        ($this->handler)(new TriggerStructureMarketSync());

        // The failure of one structure does not prevent the others from being synced.
        self::assertSame([
            '/markets/structures/' . self::DEFAULT_STRUCTURE_ID . '/',
            '/markets/structures/' . self::PREFERRED_STRUCTURE_ID . '/',
        ], $esiCalls->getArrayCopy());
        $tracker = $this->trackerState();
        self::assertSame('error', $tracker['status']);
        self::assertStringContainsString((string) self::PREFERRED_STRUCTURE_ID, $tracker['message']);
    }

    /** @param array<int, Character|null> $requesters structureId => requester */
    private function givenRequesters(array $requesters): void
    {
        $this->requesters = $requesters;
        $this->characterRepository->method('findStructureMarketRequester')
            ->willReturnCallback(fn (int $structureId): ?Character => $this->requesters[$structureId] ?? null);
    }

    /** @return \ArrayObject<int, array{string, ?EveToken}> */
    private function recordEsiCalls(): \ArrayObject
    {
        $calls = new \ArrayObject();
        $this->esiClient->method('getPaginated')
            ->willReturnCallback(function (string $endpoint, ?EveToken $token) use ($calls): array {
                $calls[] = [$endpoint, $token];

                return [];
            });

        return $calls;
    }

    /** @return array<string, mixed> */
    private function trackerState(): array
    {
        foreach ($this->syncTracker->getAll() as $state) {
            if ($state['type'] === self::SYNC_TYPE) {
                return $state;
            }
        }

        self::fail('No tracker state for ' . self::SYNC_TYPE);
    }
}
