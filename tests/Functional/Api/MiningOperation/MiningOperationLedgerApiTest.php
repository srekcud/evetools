<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Exception\EsiApiException;
use App\Message\TakeMiningLedgerSnapshot;
use App\MessageHandler\TakeMiningLedgerSnapshotHandler;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\ESI\TokenManager;
use App\Tests\Integration\MiningOperation\PersistsMiningOperations;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('issue-263')]
final class MiningOperationLedgerApiTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use PersistsMiningOperations;
    use SeedsBoostShipSde;

    private const string SPODUMAIN_LABEL = 'Anomalie Spodumain — 20:00';
    private const string STARTED_AT = '2026-11-05T20:00:00+00:00';
    private const string ENDED_AT = '2026-11-05T21:30:00+00:00';
    private const int SPODUMAIN = 19;
    private const int BISTOT = 1223;
    private const int S1 = 30_003_504;
    private const int UNKNOWN_TYPE = 999_999_263;
    private const int HULK = 22544;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $aria;
    private User $brann;
    private User $cyra;
    private User $dorn;
    private MiningOperation $spodumain;
    private MiningOperationMember $brannMembership;
    private MiningOperationMember $cyraMembership;
    private array $ledgersByEveCharacterId = [];
    private array $shipsByEveCharacterId = [];
    private array $shipRequests = [];

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::STARTED_AT));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceEsi();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->seedStaticData();
        $this->aria = $this->persistMiningPilot($this->em, 'Aria Solberg', self::C1, ['Aria II']);
        $this->brann = $this->persistMiningPilot($this->em, 'Brann Holt', self::C1, ['Brann II']);
        $this->cyra = $this->persistMiningPilot($this->em, 'Cyra Lune', self::C2);
        $this->dorn = $this->persistMiningPilot($this->em, 'Dorn Achar', null);
        $this->spodumain = $this->persistMiningOperation($this->em, $this->aria, self::SPODUMAIN_LABEL, self::STARTED_AT, ownerCharacterNames: ['Aria Solberg', 'Aria II']);
        $this->brannMembership = $this->addMiningMembership($this->spodumain, $this->brann, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Accepted, ['Brann Holt', 'Brann II']);
        $this->cyraMembership = $this->addMiningMembership($this->spodumain, $this->cyra, MiningOperationMemberRole::Member, MiningOperationMemberStatus::Pending, ['Cyra Lune']);
        $this->em->flush();

        $this->ledgerOf('Aria Solberg', [['2026-11-05', self::SPODUMAIN, self::S1, 1_000]]);
        $this->ledgerOf('Aria II', []);
        $this->ledgerOf('Brann Holt', [['2026-11-05', self::SPODUMAIN, self::S1, 12_000]]);
        $this->ledgerOf('Brann II', [['2026-11-05', self::SPODUMAIN, self::S1, 3_000]]);
    }

    public function testOperationStartedEndedAndReadAfterTheEsiCacheGivesTheMinedSpodumainOfBrannHolt(): void
    {
        $this->as($this->aria);
        $this->post('start', ['solarSystemId' => self::S1]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['in_progress', self::STARTED_AT, self::S1, 'Aldranette'],
            $this->fields($this->responseJson(), ['status', 'startedAt', 'solarSystemId', 'solarSystemName']),
        );
        self::assertSame([null, null, null, null], array_column($this->sentSnapshotMessages(), 'delay'));
        $this->deliverSentSnapshotsAt('2026-11-05T20:00:02+00:00');

        $this->as($this->aria);
        self::mockTime(new \DateTimeImmutable(self::ENDED_AT));
        $this->post('end');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['closing', self::ENDED_AT], $this->fields($this->responseJson(), ['status', 'endedAt']));
        $this->ledgerOf('Brann Holt', [['2026-11-05', self::SPODUMAIN, self::S1, 41_000]], 'Thu, 05 Nov 2026 21:37:12 GMT');
        $this->deliverSentSnapshotsAt('2026-11-05T21:30:01+00:00');
        self::assertContains(436_000, array_column($this->sentSnapshotMessages(), 'delay'));

        $this->ledgerOf('Brann Holt', [['2026-11-05', self::SPODUMAIN, self::S1, 47_500]], 'Thu, 05 Nov 2026 21:47:17 GMT');
        $this->deliverSentSnapshotsAt('2026-11-05T21:37:17+00:00');

        $this->as($this->brann);
        $this->request('GET', $this->operationUri() . '/ledger');
        self::assertResponseStatusCodeSame(200);
        $brannHolt = $this->characterOf($this->responseJson(), 'Brann Holt');
        self::assertSame(
            ['known', 35_500, [['typeId' => self::SPODUMAIN, 'typeName' => 'Spodumain', 'quantity' => 35_500, 'measuredQuantity' => 35_500, 'correction' => null]]],
            [$brannHolt['state'] ?? null, $brannHolt['totalQuantity'] ?? null, $brannHolt['quantities'] ?? null],
        );
    }

    #[Group('issue-364')]
    public function testEachCharacterShowsTheShipReadAtItsStartSnapshotAndAnUnreadableShipStaysUnknownWithoutBlockingTheSnapshot(): void
    {
        $this->seedShipTypes();
        $this->shipsByEveCharacterId[$this->eveIdOf('Aria Solberg')] = ['ship_type_id' => self::HULK];
        $this->shipsByEveCharacterId[$this->eveIdOf('Aria II')] = ['ship_type_id' => self::ORCA];
        $this->shipsByEveCharacterId[$this->eveIdOf('Brann II')] = EsiApiException::fromResponse(403, 'Access forbidden');

        $this->startedByAria();

        $this->as($this->brann);
        $this->request('GET', $this->operationUri() . '/ledger');
        $ledger = $this->responseJson();
        $shipsByCharacter = [];
        foreach (['Aria Solberg', 'Aria II', 'Brann Holt', 'Brann II'] as $characterName) {
            $character = $this->characterOf($ledger, $characterName);
            $shipsByCharacter[$characterName] = [
                $character['shipTypeId'] ?? null,
                $character['shipName'] ?? null,
                array_key_exists('shipTypeId', $character) && array_key_exists('shipName', $character),
                $character['startSnapshot']['status'] ?? null,
            ];
        }
        self::assertSame(
            [
                'Aria Solberg' => [self::HULK, 'Hulk', true, 'taken'],
                'Aria II' => [self::ORCA, 'Orca', true, 'taken'],
                'Brann Holt' => [null, null, true, 'taken'],
                'Brann II' => [null, null, true, 'taken'],
            ],
            $shipsByCharacter,
        );
        self::assertCount(4, $this->shipRequests);
    }

    #[Group('issue-364')]
    public function testShipIsNotReadAgainAtTheEndSnapshotNorChangedWhenTheCharacterSwitchesShip(): void
    {
        $this->seedShipTypes();
        $this->shipsByEveCharacterId[$this->eveIdOf('Brann Holt')] = ['ship_type_id' => self::HULK];
        $this->startedByAria();
        $this->shipsByEveCharacterId[$this->eveIdOf('Brann Holt')] = ['ship_type_id' => self::ORCA];
        $requestsAfterStart = count($this->shipRequests);

        $this->endedByAriaAt(self::ENDED_AT);
        $this->ledgerOf('Brann Holt', [['2026-11-05', self::SPODUMAIN, self::S1, 47_500]], 'Thu, 05 Nov 2026 21:47:17 GMT');
        $this->deliverSentSnapshotsAt('2026-11-05T21:37:17+00:00');

        $this->as($this->brann);
        $this->request('GET', $this->operationUri() . '/ledger');
        $brannHolt = $this->characterOf($this->responseJson(), 'Brann Holt');
        self::assertSame(
            [$requestsAfterStart, self::HULK, 'Hulk'],
            [count($this->shipRequests), $brannHolt['shipTypeId'] ?? null, $brannHolt['shipName'] ?? null],
        );
    }

    #[Group('issue-364')]
    public function testRetriedStartSnapshotOfAReconnectedCharacterReadsItsShip(): void
    {
        $this->seedShipTypes();
        $this->ledgersByEveCharacterId[$this->eveIdOf('Brann II')] = EsiApiException::fromResponse(403, 'Access forbidden');
        $this->shipsByEveCharacterId[$this->eveIdOf('Brann II')] = ['ship_type_id' => self::HULK];
        $this->startedByAria();
        $this->as($this->brann);
        $this->request('GET', $this->operationUri() . '/ledger');
        $before = $this->characterOf($this->responseJson(), 'Brann II');

        $this->ledgerOf('Brann II', [['2026-11-05', self::SPODUMAIN, self::S1, 3_000]]);
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:40:00+00:00'));
        $this->as($this->brann);
        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));
        self::assertResponseStatusCodeSame(204);
        $this->deliverSentSnapshotsAt('2026-11-05T20:40:02+00:00');
        $this->as($this->brann);
        $this->request('GET', $this->operationUri() . '/ledger');
        $after = $this->characterOf($this->responseJson(), 'Brann II');

        self::assertSame(
            [['reconnect_required', null, null], ['taken', self::HULK, 'Hulk']],
            [
                [$before['startSnapshot']['status'] ?? null, $before['shipTypeId'] ?? null, $before['shipName'] ?? null],
                [$after['startSnapshot']['status'] ?? null, $after['shipTypeId'] ?? null, $after['shipName'] ?? null],
            ],
        );
    }

    public function testStartingWithoutSolarSystemKeepsNoSystem(): void
    {
        $this->as($this->aria);

        $this->post('start', ['solarSystemId' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['in_progress', null, null], $this->fields($this->responseJson(), ['status', 'solarSystemId', 'solarSystemName']));
    }

    public function testStartingInAnUnknownSolarSystemIsRefused(): void
    {
        $this->as($this->aria);

        $this->post('start', ['solarSystemId' => 1]);

        self::assertSame([400, 'Unknown solar system'], $this->errorDetail());
        self::assertSame(MiningOperationStatus::Planned, $this->reloadedOperation()->getStatus());
    }

    public function testPlainMemberCannotStartTheOperation(): void
    {
        $this->as($this->brann);

        $this->post('start', ['solarSystemId' => self::S1]);

        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->errorDetail());
    }

    public function testOperationInProgressCannotBeStartedAgainNorAPlannedOneEnded(): void
    {
        $this->as($this->aria);
        $this->post('end');
        self::assertSame([400, 'Only an operation in progress can be ended'], $this->errorDetail());

        $this->as($this->aria);
        $this->post('start', ['solarSystemId' => self::S1]);
        $this->as($this->aria);
        $this->post('start', ['solarSystemId' => self::S1]);
        self::assertSame([400, 'Only a planned operation can be started'], $this->errorDetail());
    }

    public function testPendingPilotAcceptedAfterTheStartGetsHisStartSnapshotQueuedAtHisAcceptance(): void
    {
        $this->startedByAria();
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:13:00+00:00'));
        $this->as($this->aria);

        $this->request('PATCH', sprintf('%s/members/%s', $this->operationUri(), $this->cyraMembership->getId()), ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([null], array_column($this->sentSnapshotMessages(), 'delay'));
        $this->deliverSentSnapshotsAt('2026-11-05T20:13:02+00:00');
        $this->as($this->cyra);
        $this->request('GET', $this->operationUri() . '/ledger');
        self::assertSame(
            ['status' => 'taken', 'referenceAt' => '2026-11-05T20:13:00+00:00', 'readyAt' => '2026-11-05T20:13:00+00:00', 'takenAt' => '2026-11-05T20:13:02+00:00'],
            $this->characterOf($this->responseJson(), 'Cyra Lune')['startSnapshot'] ?? null,
        );
    }

    public function testClosingOperationNoLongerAcceptsAPendingPilot(): void
    {
        $this->startedByAria();
        $this->endedByAriaAt(self::ENDED_AT);
        $this->as($this->aria);

        $this->request('PATCH', sprintf('%s/members/%s', $this->operationUri(), $this->cyraMembership->getId()), ['status' => 'accepted']);

        self::assertSame([400, 'This operation is no longer accepting participants'], $this->errorDetail());
    }

    public function testBrannLeavesEarlyOnceAndKeepsCountingAsParticipant(): void
    {
        $this->startedByAria();
        self::mockTime(new \DateTimeImmutable('2026-11-05T21:05:00+00:00'));
        $this->as($this->brann);

        $this->post('depart');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['2026-11-05T21:05:00+00:00', 2], $this->fields($this->responseJson(), ['myDepartedAt', 'participantsCount']));
        self::assertSame([null, null], array_column($this->sentSnapshotMessages(), 'delay'));

        $this->as($this->brann);
        $this->post('depart');
        self::assertSame([400, 'You already left this operation'], $this->errorDetail());
    }

    public function testLeavingEarlyIsRefusedOnceClosingAndWithoutMembership(): void
    {
        $this->as($this->dorn);
        $this->post('depart');
        self::assertSame([404, 'Membership not found'], $this->errorDetail());

        $this->startedByAria();
        $this->endedByAriaAt(self::ENDED_AT);
        $this->as($this->brann);
        $this->post('depart');
        self::assertSame([400, 'Only an operation in progress can be left early'], $this->errorDetail());
    }

    #[Group('issue-307')]
    public function testBrannBeatsOnceTheOperationIsInProgressAndAriaSeesHimOnlineWithoutTheRawTimestamp(): void
    {
        $this->as($this->dorn);
        $this->post('presence');
        self::assertSame([404, 'Membership not found'], $this->errorDetail());

        $this->startedByAria();
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:10:00+00:00'));
        $this->as($this->brann);
        $this->post('presence');
        self::assertResponseStatusCodeSame(200);

        $this->as($this->aria);
        $this->request('GET', $this->operationUri() . '/members');
        $members = array_column($this->responseJson(), null, 'characterName');

        self::assertSame(
            [['online', 0, false], [null, null, false]],
            [
                [$members['Brann Holt']['presence'] ?? 'missing', $members['Brann Holt']['presenceIdleMinutes'] ?? 'missing', array_key_exists('lastSeenAt', $members['Brann Holt'] ?? [])],
                [$members['Cyra Lune']['presence'] ?? null, $members['Cyra Lune']['presenceIdleMinutes'] ?? null, array_key_exists('lastSeenAt', $members['Cyra Lune'] ?? [])],
            ],
        );
    }

    public function testOwnerCannotRemoveAParticipantOnceTheOperationHasStarted(): void
    {
        $this->startedByAria();
        $this->as($this->aria);

        $this->request('DELETE', sprintf('%s/members/%s', $this->operationUri(), $this->brannMembership->getId()));

        self::assertSame([409, 'Mining operation already started: use departure instead'], $this->errorDetail());
        self::assertSame(MiningOperationMemberStatus::Accepted, $this->em->find(MiningOperationMember::class, $this->brannMembership->getId())?->getStatus());
    }

    public function testCharacterOwnerRetriesHisStartSnapshotOnceReconnected(): void
    {
        $this->ledgersByEveCharacterId[$this->eveIdOf('Brann II')] = EsiApiException::fromResponse(403, 'Access forbidden');
        $this->startedByAria();
        self::mockTime(new \DateTimeImmutable('2026-11-05T20:40:00+00:00'));
        $this->as($this->cyra);
        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));
        self::assertSame([403, 'Only the character owner or an admin can retry its snapshot'], $this->errorDetail());

        $this->as($this->brann);
        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann II')));
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->sentSnapshotMessages());

        $this->as($this->aria);
        $this->post(sprintf('characters/%s/snapshot-retry', $this->enrolmentIdOf('Brann Holt')));
        self::assertSame([400, 'This snapshot cannot be retried'], $this->errorDetail());
    }

    public function testOwnerCorrectsTheUnknownQuantityOfBrannIIDuringTheClosing(): void
    {
        $this->ledgersByEveCharacterId[$this->eveIdOf('Brann II')] = EsiApiException::fromResponse(403, 'Access forbidden');
        $this->startedByAria();
        $this->endedByAriaAt(self::ENDED_AT);
        self::mockTime(new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann II')), ['typeId' => self::UNKNOWN_TYPE, 'quantity' => 20_000]);
        self::assertSame([400, 'Unknown type'], $this->errorDetail());
        $this->as($this->aria);

        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann II')), ['typeId' => self::SPODUMAIN, 'quantity' => 20_000]);

        self::assertResponseStatusCodeSame(204);
        $this->as($this->aria);
        $this->request('GET', $this->operationUri() . '/ledger');
        $brannII = $this->characterOf($this->responseJson(), 'Brann II');
        self::assertSame(
            ['known', 20_000, ['originalQuantity' => null, 'correctedQuantity' => 20_000, 'correctedByName' => 'Aria Solberg', 'correctedAt' => '2026-11-05T21:50:00+00:00']],
            [$brannII['state'] ?? null, $brannII['totalQuantity'] ?? null, $brannII['quantities'][0]['correction'] ?? null],
        );
    }

    public function testQuantityCorrectionIsRefusedInProgressWhileMeasuringForAPlainMemberAndForANegativeQuantity(): void
    {
        $this->startedByAria();
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann Holt')), ['typeId' => self::SPODUMAIN, 'quantity' => 20_000]);
        self::assertSame([400, 'Quantities can only be corrected while the operation is closing'], $this->errorDetail());

        $this->endedByAriaAt(self::ENDED_AT);
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann Holt')), ['typeId' => self::SPODUMAIN, 'quantity' => 20_000]);
        self::assertSame([400, 'This quantity is still being measured'], $this->errorDetail());

        $this->as($this->brann);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann Holt')), ['typeId' => self::SPODUMAIN, 'quantity' => 20_000]);
        self::assertSame([403, 'Only the operation owner or an admin can perform this action'], $this->errorDetail());

        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann Holt')), ['typeId' => self::SPODUMAIN, 'quantity' => -1]);
        self::assertSame([422, ['quantity']], [$this->client->getResponse()->getStatusCode(), $this->violatedPaths()]);
    }

    public function testCharacterOfAnotherOperationIsNotFound(): void
    {
        $iceBelt = $this->persistMiningOperation($this->em, $this->cyra, 'Ice belt — dimanche', '2026-11-08T18:00:00+00:00', corporationId: self::C2, ownerCharacterNames: ['Cyra Lune']);
        $iceBelt->setStatus(MiningOperationStatus::Closing);
        $this->em->flush();
        $this->as($this->aria);

        $this->post(sprintf('characters/%s/quantity-corrections', $iceBelt->getMembers()->first()->getCharacters()->first()->getId()), ['typeId' => self::SPODUMAIN, 'quantity' => 20_000]);

        self::assertSame([404, 'Character not found'], $this->errorDetail());
    }

    public function testPendingPilotCannotReadTheLedgerWhilePlannedParticipantsAreListed(): void
    {
        $this->as($this->cyra);
        $this->request('GET', $this->operationUri() . '/ledger');
        self::assertSame([403, 'You do not have access to this operation'], $this->errorDetail());

        $this->as($this->aria);
        $this->request('GET', $this->operationUri() . '/ledger');
        self::assertResponseStatusCodeSame(200);
        $ledger = $this->responseJson();
        self::assertSame(
            ['planned', ['Aria Solberg', 'Brann Holt'], ['measuring', 'measuring'], 0],
            [$ledger['status'] ?? null, array_column($ledger['participants'] ?? [], 'characterName'), array_column($ledger['participants'] ?? [], 'state'), $ledger['endSnapshotsTotal'] ?? null],
        );
    }

    #[Group('issue-311')]
    public function testPorpoiseWithAsteroidAndGasCompressorsCompressesSpodumainOnSiteButNeitherZeolitesNorBlueIce(): void
    {
        $this->declaredBoost(self::PORPOISE, ['asteroid', 'gas']);
        $this->startedByAria();
        $this->endedWithTheFinalReadingOfBrannHolt([['2026-11-05', self::SPODUMAIN, self::S1, 17_000], ['2026-11-05', self::ZEOLITES, self::S1, 2_000], ['2026-11-05', self::BLUE_ICE, self::S1, 400]]);
        $this->as($this->brann);
        $this->request('GET', $this->operationUri() . '/ledger');
        self::assertSame(
            [['Blue Ice', 400], ['Spodumain', 5_000], ['Zeolites', 2_000]],
            array_map(static fn (array $quantity): array => [$quantity['typeName'], $quantity['quantity']], $this->characterOf($this->responseJson(), 'Brann Holt')['quantities'] ?? []),
        );

        self::assertSame(
            [
                'boostShipTypeId' => self::PORPOISE,
                'boostShipName' => 'Porpoise',
                'mountedCompressors' => [
                    ['kind' => 'asteroid', 'typeId' => 62622, 'typeName' => 'Medium Asteroid Ore Compressor I'],
                    ['kind' => 'gas', 'typeId' => 62624, 'typeName' => 'Medium Gas Compressor I'],
                ],
                'types' => [
                    ['typeId' => self::SPODUMAIN, 'compressible' => true, 'status' => 'on_site'],
                    ['typeId' => self::BLUE_ICE, 'compressible' => false, 'status' => 'no_hull_compressor'],
                    ['typeId' => self::ZEOLITES, 'compressible' => false, 'status' => 'no_hull_compressor'],
                ],
                'gaps' => ['noHull' => ['ice', 'moon'], 'notMounted' => []],
            ],
            $this->compressionReadBy($this->brann),
        );
    }

    #[Group('issue-311')]
    public function testBlueIceIsNotMountedOnTheOrcaWithOnlyAnAsteroidCompressorAndSpodumainWithoutMinedQuantityIsNotListed(): void
    {
        $this->declaredBoost(self::ORCA, ['asteroid']);
        $this->startedByAria();
        $this->endedWithTheFinalReadingOfBrannHolt([['2026-11-05', self::SPODUMAIN, self::S1, 12_000], ['2026-11-05', self::BLUE_ICE, self::S1, 400]]);

        $compression = $this->compressionReadBy($this->aria);

        self::assertSame(
            [
                [['typeId' => self::BLUE_ICE, 'compressible' => false, 'status' => 'not_mounted']],
                ['noHull' => [], 'notMounted' => ['ice']],
            ],
            [$compression['types'] ?? null, $compression['gaps'] ?? null],
        );
    }

    #[Group('issue-311')]
    public function testTypeAbsentFromTheSdeIsUnknownOreKindEvenWithEveryCompressorMountedOnTheOrca(): void
    {
        $this->declaredBoost(self::ORCA, ['asteroid', 'gas', 'ice', 'mercoxit', 'moon']);
        $this->startedByAria();
        $this->endedWithTheFinalReadingOfBrannHolt([['2026-11-05', self::SPODUMAIN, self::S1, 12_000], ['2026-11-05', self::UNKNOWN_TYPE, self::S1, 700]]);

        $compression = $this->compressionReadBy($this->aria);

        self::assertSame(
            [
                [['typeId' => self::UNKNOWN_TYPE, 'compressible' => null, 'status' => 'unknown_ore_kind']],
                ['noHull' => [], 'notMounted' => []],
            ],
            [$compression['types'] ?? null, $compression['gaps'] ?? null],
        );
    }

    #[Group('issue-311')]
    public function testOperationWithoutBoostShipTellsNothingAboutCompression(): void
    {
        $this->startedByAria();
        $this->endedWithTheFinalReadingOfBrannHolt([['2026-11-05', self::SPODUMAIN, self::S1, 17_000]]);

        self::assertSame(
            [
                'boostShipTypeId' => null,
                'boostShipName' => null,
                'mountedCompressors' => [],
                'types' => [['typeId' => self::SPODUMAIN, 'compressible' => null, 'status' => 'no_boost_ship']],
                'gaps' => ['noHull' => [], 'notMounted' => []],
            ],
            $this->compressionReadBy($this->brann),
        );
    }

    #[Group('issue-311')]
    public function testBlueIceOfAnOwnerCorrectionCountsAmongTheTypesOfTheOperation(): void
    {
        $this->declaredBoost(self::ORCA, ['asteroid']);
        $this->ledgersByEveCharacterId[$this->eveIdOf('Brann II')] = EsiApiException::fromResponse(403, 'Access forbidden');
        $this->startedByAria();
        $this->endedByAriaAt(self::ENDED_AT);
        $this->as($this->aria);
        $this->post(sprintf('characters/%s/quantity-corrections', $this->enrolmentIdOf('Brann II')), ['typeId' => self::BLUE_ICE, 'quantity' => 20_000]);
        self::assertResponseStatusCodeSame(204);

        $compression = $this->compressionReadBy($this->aria);

        self::assertSame(
            [[['typeId' => self::BLUE_ICE, 'compressible' => false, 'status' => 'not_mounted']], ['noHull' => [], 'notMounted' => ['ice']]],
            [$compression['types'] ?? null, $compression['gaps'] ?? null],
        );
    }

    private function endedWithTheFinalReadingOfBrannHolt(array $rows): void
    {
        $this->ledgerOf('Brann Holt', $rows, 'Thu, 05 Nov 2026 21:37:12 GMT');
        $this->endedByAriaAt(self::ENDED_AT);
        $this->ledgerOf('Brann Holt', $rows, 'Thu, 05 Nov 2026 21:47:17 GMT');
        $this->deliverSentSnapshotsAt('2026-11-05T21:37:17+00:00');
    }

    private function declaredBoost(int $boostShipTypeId, array $mountedCompressorKinds): void
    {
        $this->as($this->aria);
        $this->request('PATCH', $this->operationUri() . '/valuation', ['boostShipTypeId' => $boostShipTypeId, 'mountedCompressorKinds' => $mountedCompressorKinds]);
        self::assertResponseStatusCodeSame(200);
    }

    private function compressionReadBy(User $user): array
    {
        $this->as($user);
        $this->request('GET', $this->operationUri() . '/ledger');
        self::assertResponseStatusCodeSame(200);

        return $this->responseJson()['compression'] ?? [];
    }

    private function startedByAria(): void
    {
        self::mockTime(new \DateTimeImmutable(self::STARTED_AT));
        $this->as($this->aria);
        $this->post('start', ['solarSystemId' => self::S1]);
        self::assertResponseStatusCodeSame(200);
        $this->deliverSentSnapshotsAt('2026-11-05T20:00:02+00:00');
    }

    private function endedByAriaAt(string $endedAt): void
    {
        self::mockTime(new \DateTimeImmutable($endedAt));
        $this->as($this->aria);
        $this->post('end');
        self::assertResponseStatusCodeSame(200);
        $this->deliverSentSnapshotsAt($endedAt);
    }

    private function deliverSentSnapshotsAt(string $deliveredAt): void
    {
        $messages = $this->sentSnapshotMessages();
        $this->asyncTransport()->reset();
        self::mockTime(new \DateTimeImmutable($deliveredAt));
        $handler = self::getContainer()->get(TakeMiningLedgerSnapshotHandler::class);
        foreach ($messages as $message) {
            $handler(new TakeMiningLedgerSnapshot($message['snapshotId']));
        }
    }

    private function sentSnapshotMessages(): array
    {
        $messages = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            \assert($envelope instanceof Envelope);
            if ($envelope->getMessage() instanceof TakeMiningLedgerSnapshot) {
                $messages[] = ['snapshotId' => $envelope->getMessage()->snapshotId, 'delay' => $envelope->last(DelayStamp::class)?->getDelay()];
            }
        }

        return $messages;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function ledgerOf(string $characterName, array $rows, ?string $expires = null): void
    {
        $this->ledgersByEveCharacterId[$this->eveIdOf($characterName)] = new PaginatedEsiResponse(
            array_map(static fn (array $row): array => ['date' => $row[0], 'type_id' => $row[1], 'solar_system_id' => $row[2], 'quantity' => $row[3]], $rows),
            $expires === null ? null : new \DateTimeImmutable($expires),
        );
    }

    private function replaceEsi(): void
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginatedWithExpiry')->willReturnCallback(function (string $endpoint): PaginatedEsiResponse {
            preg_match('#/characters/(\d+)/mining/#', $endpoint, $matches);
            $ledger = $this->ledgersByEveCharacterId[(int) ($matches[1] ?? 0)] ?? new PaginatedEsiResponse([], null);
            if ($ledger instanceof \Throwable) {
                throw $ledger;
            }

            return $ledger;
        });
        $esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->shipRequests[] = $endpoint;
            preg_match('#/characters/(\d+)/ship/#', $endpoint, $matches);
            $ship = $this->shipsByEveCharacterId[(int) ($matches[1] ?? 0)] ?? [];
            if ($ship instanceof \Throwable) {
                throw $ship;
            }

            return $ship;
        });
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('access-token');
        self::getContainer()->set(EsiClient::class, $esiClient);
        self::getContainer()->set(TokenManager::class, $tokenManager);
        self::getContainer()->set('logger', new NullLogger());
    }

    private function as(User $user): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->replaceEsi();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->em->find(User::class, $user->getId()), 'api');
    }

    private function seedStaticData(): void
    {
        $region = (new MapRegion())->setRegionId(10_000_032)->setRegionName('Sinq Laison');
        $constellation = (new MapConstellation())->setConstellationId(20_000_441)->setConstellationName('Ancbeu')->setRegion($region);
        $aldranette = (new MapSolarSystem())->setSolarSystemId(self::S1)->setSolarSystemName('Aldranette')->setConstellation($constellation)->setRegionId(10_000_032);
        $category = (new InvCategory())->setCategoryId(25)->setCategoryName('Asteroid');
        $spodumainGroup = (new InvGroup())->setGroupId(461)->setGroupName('Spodumain')->setCategory($category);
        $bistotGroup = (new InvGroup())->setGroupId(451)->setGroupName('Bistot')->setCategory($category);
        foreach ([$region, $constellation, $aldranette, $category, $spodumainGroup, $bistotGroup] as $staticRow) {
            $this->em->persist($staticRow);
        }
        $this->em->persist((new InvType())->setTypeId(self::SPODUMAIN)->setTypeName('Spodumain')->setGroup($spodumainGroup));
        $this->em->persist((new InvType())->setTypeId(self::BISTOT)->setTypeName('Bistot')->setGroup($bistotGroup));
        $this->seedBoostShipSde($this->em);
    }

    private function seedShipTypes(): void
    {
        $miningBarges = $this->em->find(InvGroup::class, 463);
        \assert($miningBarges instanceof InvGroup);
        $this->em->persist((new InvType())->setTypeId(self::HULK)->setTypeName('Hulk')->setGroup($miningBarges)->setPublished(true));
        $this->em->flush();
    }

    private function eveIdOf(string $characterName): int
    {
        return $this->miningCharacters[$characterName]->getEveCharacterId();
    }

    private function enrolmentIdOf(string $characterName): string
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        return (string) $connection->fetchOne(
            'SELECT c.id FROM mining_operation_characters c JOIN mining_operation_members m ON m.id = c.member_id WHERE m.operation_id::text = ? AND c.character_id::text = ?',
            [(string) $this->spodumain->getId(), (string) $this->miningCharacters[$characterName]->getId()],
        );
    }

    private function reloadedOperation(): MiningOperation
    {
        $operation = self::getContainer()->get(EntityManagerInterface::class)->find(MiningOperation::class, $this->spodumain->getId());
        \assert($operation instanceof MiningOperation);

        return $operation;
    }

    private function operationUri(): string
    {
        return '/api/mining-operations/' . $this->spodumain->getId();
    }

    private function post(string $action, array $body = []): void
    {
        $this->request('POST', $this->operationUri() . '/' . $action, $body);
    }

    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => $method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function responseJson(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }

    private function fields(array $resource, array $names): array
    {
        return array_map(static fn (string $name): mixed => $resource[$name] ?? null, $names);
    }

    private function characterOf(array $ledger, string $characterName): array
    {
        foreach ($ledger['participants'] ?? [] as $participant) {
            foreach ($participant['characters'] ?? [] as $character) {
                if ($character['name'] === $characterName) {
                    return $character;
                }
            }
        }

        return [];
    }

    private function errorDetail(): array
    {
        $error = $this->responseJson();

        return [$error['status'] ?? $this->client->getResponse()->getStatusCode(), $error['detail'] ?? null];
    }

    private function violatedPaths(): array
    {
        return array_values(array_unique(array_column($this->responseJson()['violations'] ?? [], 'propertyPath')));
    }
}
