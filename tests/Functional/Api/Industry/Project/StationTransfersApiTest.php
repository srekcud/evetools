<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStationTransfer;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\TransferCarrierKind;
use App\Dto\Transfer\TransferEndpoint;
use App\Dto\Transfer\TransferLine;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1295')]
final class StationTransfersApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int AMARR_SYSTEM_ID = 30_002_187;
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int UNKNOWN_SYSTEM_ID = 30_999_999;
    private const int BEYOND_INT4 = 3_000_000_000;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;
    private const int CATEGORY_ID = 999_295_001;
    private const int GROUP_ID = 999_295_002;
    private const int STATION_A_LOCATION_ID = 1_042_000_000_011;
    private const int STATION_B_LOCATION_ID = 1_042_000_000_022;
    private const int CONTRACT_ID = 1_295_001;
    private const int UNKNOWN_CHARACTER_ID = 91_295_001;
    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e95';
    private const string UNKNOWN_TRANSFER_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e96';
    private const string EARLIER_DECLARED_AT = '2026-11-20T10:00:00+00:00';
    private const string LATER_DECLARED_AT = '2026-11-21T10:00:00+00:00';
    private const string STATION_TRANSFER_CHANGED = 'station_transfer_changed';

    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedSolarSystemsAndVolumes();
        $stationA = $this->persistStructure('Structure A', self::STATION_A_LOCATION_ID, self::PERIMETER_SYSTEM_ID);
        $stationB = $this->persistStructure('Structure B', self::STATION_B_LOCATION_ID, self::AMARR_SYSTEM_ID);
        $this->ishtarBatch->setWorkStations([
            'final_products' => ['structureId' => $stationA->getId()->toRfc4122(), 'recommendedStructureId' => null],
            'components' => ['structureId' => $stationB->getId()->toRfc4122(), 'recommendedStructureId' => null],
        ]);
        $this->em->flush();

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testMemberDeclaresJitaToAPlanStationOutsideTheHubAsTwoLegsOfTheSameRoute(): void
    {
        $memberEveCharacterId = $this->member->getMainCharacter()->getEveCharacterId();

        $this->actAs($this->member);
        $this->request('POST', $this->transfersUri(), [
            'fromSolarSystemId' => self::JITA_SYSTEM_ID,
            'fromLocationId' => null,
            'toSolarSystemId' => self::PERIMETER_SYSTEM_ID,
            'toLocationId' => self::STATION_A_LOCATION_ID,
            'lines' => [['typeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 500], ['typeId' => self::MORPHITE_TYPE_ID, 'quantity' => 20]],
            'carrierCharacterId' => $memberEveCharacterId,
            'contractId' => self::CONTRACT_ID,
        ]);

        self::assertResponseStatusCodeSame(201);
        $leg = $this->responseJson();
        self::assertSame(
            ['arrivedAt', 'carrier', 'declaredAt', 'declaredBy', 'expectedArrivalAt', 'from', 'id', 'legCount', 'legIndex', 'lines', 'routeId', 'state', 'to', 'unknownReason', 'volume'],
            $this->sortedKeys($leg),
        );
        self::assertSame(
            [0, 2, 'Brann Holt', 'in_transit', 15.0],
            [$leg['legIndex'], $leg['legCount'], $leg['declaredBy'], $leg['state'], $leg['volume']],
        );
        self::assertArrayHasKey('arrivedAt', $leg);
        self::assertNull($leg['arrivedAt']);
        self::assertArrayHasKey('unknownReason', $leg);
        self::assertNull($leg['unknownReason']);
        self::assertSame(['kind' => 'courier', 'characterId' => $memberEveCharacterId, 'contractId' => self::CONTRACT_ID], $leg['carrier']);
        self::assertSame(['solarSystemId' => self::JITA_SYSTEM_ID, 'solarSystemName' => 'Jita', 'locationId' => null, 'stationName' => null], $leg['from']);
        self::assertSame(['solarSystemId' => self::HUB_SYSTEM_ID, 'solarSystemName' => 'C-J6MT', 'locationId' => null, 'stationName' => null], $leg['to']);
        self::assertArrayHasKey('locationId', $leg['from']);
        self::assertArrayHasKey('stationName', $leg['to']);
        self::assertSame(
            [
                [self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 5.0, 500, 0, 500, 'declared', 'pending', null],
                [self::MORPHITE_TYPE_ID, 'Morphite', 20, 10.0, 20, 0, 20, 'declared', 'pending', null],
            ],
            $this->lineRowsOf($leg),
        );
        $declaredAt = new \DateTimeImmutable($leg['declaredAt']);
        self::assertSame($declaredAt->modify('+3 days')->getTimestamp(), (new \DateTimeImmutable($leg['expectedArrivalAt']))->getTimestamp());

        $stored = $this->storedTransfers();
        self::assertCount(2, $stored);
        self::assertSame(
            [
                [0, 'courier', self::JITA_SYSTEM_ID, null, self::HUB_SYSTEM_ID, null, $memberEveCharacterId, self::CONTRACT_ID],
                [1, 'jump_bridge', self::HUB_SYSTEM_ID, null, self::PERIMETER_SYSTEM_ID, self::STATION_A_LOCATION_ID, null, null],
            ],
            array_map(static fn (IndustryProjectStationTransfer $transfer): array => [
                $transfer->getLegIndex(),
                $transfer->getCarrierKind()->value,
                $transfer->getFrom()->solarSystemId,
                $transfer->getFrom()->locationId,
                $transfer->getTo()->solarSystemId,
                $transfer->getTo()->locationId,
                $transfer->getCarrierCharacterId(),
                $transfer->getContractId(),
            ], $stored),
        );
        self::assertSame([$leg['routeId'], $leg['routeId']], array_map(static fn (IndustryProjectStationTransfer $transfer): string => $transfer->getRouteId()->toRfc4122(), $stored));
        self::assertSame($leg['id'], $stored[0]->getId()->toRfc4122());
    }

    public function testDeclaringBetweenTwoPlanStationsIsOneJumpBridgeLegWithStationNamesAndSumsTheLinesOfTheSameType(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->transfersUri(), [
            'fromSolarSystemId' => self::PERIMETER_SYSTEM_ID,
            'fromLocationId' => self::STATION_A_LOCATION_ID,
            'toSolarSystemId' => self::AMARR_SYSTEM_ID,
            'toLocationId' => self::STATION_B_LOCATION_ID,
            'lines' => [['typeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 300], ['typeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 200]],
            'carrierCharacterId' => null,
            'contractId' => null,
        ]);

        self::assertResponseStatusCodeSame(201);
        $leg = $this->responseJson();
        self::assertSame([0, 1, 5.0], [$leg['legIndex'], $leg['legCount'], $leg['volume']]);
        self::assertSame(
            ['solarSystemId' => self::PERIMETER_SYSTEM_ID, 'solarSystemName' => 'Perimeter', 'locationId' => self::STATION_A_LOCATION_ID, 'stationName' => 'Structure A'],
            $leg['from'],
        );
        self::assertSame(
            ['solarSystemId' => self::AMARR_SYSTEM_ID, 'solarSystemName' => 'Amarr', 'locationId' => self::STATION_B_LOCATION_ID, 'stationName' => 'Structure B'],
            $leg['to'],
        );
        self::assertSame(['kind' => 'jump_bridge', 'characterId' => null, 'contractId' => null], $leg['carrier']);
        self::assertArrayHasKey('characterId', $leg['carrier']);
        self::assertArrayHasKey('contractId', $leg['carrier']);
        self::assertSame([[self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 5.0, 500, 0, 500, 'declared', 'pending', null]], $this->lineRowsOf($leg));
        self::assertCount(1, $this->storedTransfers());
    }

    #[DataProvider('refusedDeclarations')]
    public function testRefusedDeclarationIsA422AndStoresNothing(array $body): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->transfersUri(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedTransfers());
    }

    public static function refusedDeclarations(): iterable
    {
        $valid = self::validDeclaration();

        yield 'empty lines' => [array_replace($valid, ['lines' => []])];
        yield 'quantity zero' => [array_replace($valid, ['lines' => [['typeId' => 34, 'quantity' => 0]]])];
        yield 'quantity negative' => [array_replace($valid, ['lines' => [['typeId' => 34, 'quantity' => -5]]])];
        yield 'type outside the bill of materials' => [array_replace($valid, ['lines' => [['typeId' => 35683, 'quantity' => 5]]])];
        yield 'same station' => [array_replace($valid, ['fromSolarSystemId' => 30_000_144, 'fromLocationId' => 1_042_000_000_011])];
        yield 'same system without station' => [array_replace($valid, ['fromSolarSystemId' => 30_000_144, 'fromLocationId' => null, 'toLocationId' => null])];
        yield 'unknown arrival system' => [array_replace($valid, ['toSolarSystemId' => 30_999_999])];
        yield 'unknown departure system' => [array_replace($valid, ['fromSolarSystemId' => 30_999_998])];
        yield 'system beyond int4' => [array_replace($valid, ['toSolarSystemId' => 3_000_000_000])];
        yield 'without fromSolarSystemId' => [array_diff_key($valid, ['fromSolarSystemId' => true])];
        yield 'without toSolarSystemId' => [array_diff_key($valid, ['toSolarSystemId' => true])];
        yield 'without lines' => [array_diff_key($valid, ['lines' => true])];
        yield 'line without typeId' => [array_replace($valid, ['lines' => [['quantity' => 5]]])];
        yield 'line without quantity' => [array_replace($valid, ['lines' => [['typeId' => 34]]])];
        yield 'quantity beyond the maximum' => [array_replace($valid, ['lines' => [['typeId' => 34, 'quantity' => 2_147_483_648]]])];
        yield 'too many lines' => [array_replace($valid, ['lines' => array_fill(0, 501, ['typeId' => 34, 'quantity' => 1])])];
        yield 'same type summed beyond the maximum' => [array_replace($valid, ['lines' => [['typeId' => 34, 'quantity' => 2_147_483_647], ['typeId' => 34, 'quantity' => 1]]])];
    }

    public function testCarrierThatIsNotACharacterOfTheDeclarerIsRefused(): void
    {
        foreach ([$this->owner->getMainCharacter()->getEveCharacterId(), self::UNKNOWN_CHARACTER_ID] as $carrierCharacterId) {
            $this->actAs($this->member);
            $this->request('POST', $this->transfersUri(), array_replace(self::validDeclaration(), ['carrierCharacterId' => $carrierCharacterId]));

            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([], $this->storedTransfers());
    }

    public function testDeclaringAndDeletingOnACompletedProjectAreRefusedWith409(): void
    {
        [$leg] = $this->persistJumpBridgeBetweenStations($this->member, self::EARLIER_DECLARED_AT);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('POST', $this->transfersUri(), self::validDeclaration());
        self::assertResponseStatusCodeSame(409);

        $this->actAs($this->member);
        $this->request('DELETE', $this->transferUri($leg));
        self::assertResponseStatusCodeSame(409);

        self::assertCount(1, $this->storedTransfers());
    }

    public function testAcceptedMemberReadsEveryLegOrderedByDeclarationThenRouteThenLegWithTheComputedState(): void
    {
        [$earlier] = $this->persistJumpBridgeBetweenStations($this->owner, self::EARLIER_DECLARED_AT);
        [$hubLeg, $bridgeLeg] = $this->persistJitaToStationARoute($this->member, self::LATER_DECLARED_AT);

        $this->actAs($this->member);
        $this->request('GET', $this->transfersUri());

        self::assertResponseStatusCodeSame(200);
        $legs = $this->responseJson();
        self::assertSame(
            [
                [$earlier->getId()->toRfc4122(), 0, 1, 'Aria Solberg', 'in_transit', 10.0],
                [$hubLeg->getId()->toRfc4122(), 0, 2, 'Brann Holt', 'in_transit', 7.0],
                [$bridgeLeg->getId()->toRfc4122(), 1, 2, 'Brann Holt', 'awaiting_departure', 7.0],
            ],
            array_map(static fn (array $leg): array => [$leg['id'], $leg['legIndex'], $leg['legCount'], $leg['declaredBy'], $leg['state'], $leg['volume']], $legs),
        );
        self::assertSame($hubLeg->getRouteId()->toRfc4122(), $legs[1]['routeId']);
        self::assertSame($legs[1]['routeId'], $legs[2]['routeId']);
        self::assertSame(
            [
                [self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 5.0, 500, 0, 500, 'declared', 'pending', null],
                [self::NOCXIUM_TYPE_ID, 'Nocxium', 40, 2.0, 40, 0, 40, 'declared', 'pending', null],
            ],
            $this->lineRowsOf($legs[1]),
        );
        self::assertSame(
            [
                [self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 5.0, 0, 0, 0, 'declared', 'pending', null],
                [self::NOCXIUM_TYPE_ID, 'Nocxium', 40, 2.0, 0, 0, 0, 'declared', 'pending', null],
            ],
            $this->lineRowsOf($legs[2]),
        );
        self::assertSame('jump_bridge', $legs[2]['carrier']['kind']);
        self::assertSame(['solarSystemId' => self::PERIMETER_SYSTEM_ID, 'solarSystemName' => 'Perimeter', 'locationId' => self::STATION_A_LOCATION_ID, 'stationName' => 'Structure A'], $legs[2]['to']);
    }

    public function testProjectWithoutTransferReadsAnEmptyList(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->transfersUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson());
    }

    public function testPilotOutsideTheProjectAndPendingMemberAreRefusedReadingAndDeclaring(): void
    {
        foreach ([$this->outsider, $this->pendingMember] as $user) {
            $this->actAs($user);
            $this->request('GET', $this->transfersUri());
            self::assertResponseStatusCodeSame(403);
            self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());

            $this->actAs($user);
            $this->request('POST', $this->transfersUri(), self::validDeclaration());
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame([], $this->storedTransfers());
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFound(): void
    {
        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/station-transfers');

            self::assertResponseStatusCodeSame(404);
            self::assertSame([404, 'Project not found'], $this->errorDetail());
        }
    }

    public function testTransfersAreRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', $this->transfersUri());
        self::assertResponseStatusCodeSame(401);

        $this->request('POST', $this->transfersUri(), self::validDeclaration());
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeclarerMarksTheFirstLegArrivedAndTheLegReadsAsArrivedByHand(): void
    {
        [$hubLeg] = $this->persistJitaToStationARoute($this->member, self::LATER_DECLARED_AT);

        $this->actAs($this->member);
        $this->request('POST', $this->transferUri($hubLeg).'/arrive');

        self::assertResponseStatusCodeSame(200);
        $leg = $this->responseJson();
        self::assertSame([$hubLeg->getId()->toRfc4122(), 'arrived'], [$leg['id'], $leg['state']]);
        self::assertNotNull($leg['arrivedAt']);
        self::assertSame(
            [
                [self::TRITANIUM_TYPE_ID, 'Tritanium', 500, 5.0, 500, 500, 0, 'declared', 'declared', null],
                [self::NOCXIUM_TYPE_ID, 'Nocxium', 40, 2.0, 40, 40, 0, 'declared', 'declared', null],
            ],
            $this->lineRowsOf($leg),
        );
        self::assertNotNull($this->storedLeg($hubLeg)->getArrivedAt());
    }

    public function testLegCannotBeMarkedArrivedWhileTheLegBeforeItHasNotArrived(): void
    {
        [, $bridgeLeg] = $this->persistJitaToStationARoute($this->member, self::LATER_DECLARED_AT);

        $this->actAs($this->member);
        $this->request('POST', $this->transferUri($bridgeLeg).'/arrive');

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->storedLeg($bridgeLeg)->getArrivedAt());
    }

    public function testLegOfACompletedProjectCannotBeMarkedArrived(): void
    {
        [$leg] = $this->persistJumpBridgeBetweenStations($this->member, self::EARLIER_DECLARED_AT);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('POST', $this->transferUri($leg).'/arrive');

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->storedLeg($leg)->getArrivedAt());
    }

    public function testLegAlreadyArrivedCannotBeMarkedArrivedAgain(): void
    {
        [$hubLeg] = $this->persistJitaToStationARoute($this->member, self::LATER_DECLARED_AT);
        $hubLeg->markArrived(new \DateTimeImmutable('2026-11-21T12:00:00+00:00'));
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('POST', $this->transferUri($hubLeg).'/arrive');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('2026-11-21T12:00:00+00:00', $this->storedLeg($hubLeg)->getArrivedAt()?->format(\DATE_ATOM));
    }

    public function testMemberWhoIsNeitherTheDeclarerNorTheOwnerCannotMarkALegArrived(): void
    {
        [$leg] = $this->persistJumpBridgeBetweenStations($this->owner, self::EARLIER_DECLARED_AT);

        $this->actAs($this->member);
        $this->request('POST', $this->transferUri($leg).'/arrive');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->storedLeg($leg)->getArrivedAt());
    }

    public function testOwnerMarksALegDeclaredByAMemberArrived(): void
    {
        [$leg] = $this->persistJumpBridgeBetweenStations($this->member, self::EARLIER_DECLARED_AT);

        $this->actAs($this->owner);
        $this->request('POST', $this->transferUri($leg).'/arrive');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('arrived', $this->responseJson()['state']);
        self::assertNotNull($this->storedLeg($leg)->getArrivedAt());
    }

    public function testArrivingALegThatBelongsToAnotherProjectIsNotFound(): void
    {
        $foreignLeg = new IndustryProjectStationTransfer(
            $this->hecateBatch,
            $this->owner,
            Uuid::v7(),
            0,
            TransferCarrierKind::JumpBridge,
            new TransferEndpoint(self::PERIMETER_SYSTEM_ID, self::STATION_A_LOCATION_ID),
            new TransferEndpoint(self::AMARR_SYSTEM_ID, self::STATION_B_LOCATION_ID),
            [new TransferLine(self::TRITANIUM_TYPE_ID, 100, null, null)],
            new \DateTimeImmutable(self::EARLIER_DECLARED_AT),
        );
        $this->em->persist($foreignLeg);
        $this->em->flush();

        foreach ([$foreignLeg->getId()->toRfc4122(), self::UNKNOWN_TRANSFER_ID] as $transferId) {
            $this->actAs($this->owner);
            $this->request('POST', $this->transfersUri().'/'.$transferId.'/arrive');

            self::assertResponseStatusCodeSame(404);
        }
        self::assertNull($this->storedLeg($foreignLeg)->getArrivedAt());
    }

    public function testDeclarerDeletesALegAndTheLegsAfterItOnTheSameRouteAreDeleted(): void
    {
        [$kept] = $this->persistJumpBridgeBetweenStations($this->owner, self::EARLIER_DECLARED_AT);
        [$hubLeg] = $this->persistJitaToStationARoute($this->member, self::LATER_DECLARED_AT);

        $this->actAs($this->member);
        $this->request('DELETE', $this->transferUri($hubLeg));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$kept->getId()->toRfc4122()], array_map(static fn (IndustryProjectStationTransfer $transfer): string => $transfer->getId()->toRfc4122(), $this->storedTransfers()));
    }

    public function testLegThatHasArrivedIsNeverDeleted(): void
    {
        [$hubLeg] = $this->persistJitaToStationARoute($this->member, self::LATER_DECLARED_AT);
        $hubLeg->markArrived(new \DateTimeImmutable('2026-11-21T12:00:00+00:00'));
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('DELETE', $this->transferUri($hubLeg));

        self::assertResponseStatusCodeSame(409);
        self::assertCount(2, $this->storedTransfers());
    }

    public function testMemberWhoIsNeitherTheDeclarerNorTheOwnerCannotDeleteALeg(): void
    {
        [$leg] = $this->persistJumpBridgeBetweenStations($this->owner, self::EARLIER_DECLARED_AT);

        $this->actAs($this->member);
        $this->request('DELETE', $this->transferUri($leg));

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->storedTransfers());
    }

    public function testDeclaringArrivingAndDeletingEachPublishAStationTransferChangedEventOnTheProjectTopic(): void
    {
        $this->actAs($this->member);
        $this->request('POST', $this->transfersUri(), self::validDeclaration());
        self::assertResponseStatusCodeSame(201);
        $declared = $this->responseJson();

        $this->actAs($this->member);
        $this->request('POST', $this->transfersUri().'/'.$declared['id'].'/arrive');
        self::assertResponseStatusCodeSame(200);

        $this->actAs($this->member);
        $this->request('DELETE', $this->transfersUri().'/'.$declared['id']);
        self::assertResponseStatusCodeSame(409);

        [$otherLeg] = $this->persistJumpBridgeBetweenStations($this->member, self::EARLIER_DECLARED_AT);
        $this->actAs($this->member);
        $this->request('DELETE', $this->transferUri($otherLeg));
        self::assertResponseStatusCodeSame(204);

        $projectId = $this->ishtarBatch->getId()->toRfc4122();
        $events = array_values(array_filter(array_map(
            static fn (Update $update): array => ['topic' => $update->getTopics()[0], 'payload' => json_decode($update->getData(), true)],
            $this->publishedUpdates,
        ), static fn (array $event): bool => self::STATION_TRANSFER_CHANGED === $event['payload']['action']));

        $rows = array_map(static function (array $event): array {
            $data = $event['payload']['data'];
            ksort($data);

            return [$event['topic'], $data];
        }, $events);

        self::assertContains(['/group-project/'.$projectId.'/events', ['action' => 'declared', 'legIndex' => 0, 'routeId' => $declared['routeId']]], $rows);
        self::assertContains(['/group-project/'.$projectId.'/events', ['action' => 'arrived', 'legIndex' => 0, 'routeId' => $declared['routeId']]], $rows);
        self::assertContains(['/group-project/'.$projectId.'/events', ['action' => 'deleted', 'legIndex' => 0, 'routeId' => $otherLeg->getRouteId()->toRfc4122()]], $rows);
        self::assertSame(3, \count(array_filter($rows, static fn (array $row): bool => 0 === $row[1]['legIndex'])));
    }

    private static function validDeclaration(): array
    {
        return [
            'fromSolarSystemId' => self::JITA_SYSTEM_ID,
            'fromLocationId' => null,
            'toSolarSystemId' => self::PERIMETER_SYSTEM_ID,
            'toLocationId' => self::STATION_A_LOCATION_ID,
            'lines' => [['typeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 500]],
            'carrierCharacterId' => null,
            'contractId' => null,
        ];
    }

    private function transfersUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/station-transfers';
    }

    private function transferUri(IndustryProjectStationTransfer $leg): string
    {
        return $this->transfersUri().'/'.$leg->getId()->toRfc4122();
    }

    private function storedTransfers(): array
    {
        $this->em->clear();
        $stored = $this->em->getRepository(IndustryProjectStationTransfer::class)->findBy(['project' => $this->ishtarBatch->getId()]);
        usort($stored, static fn (IndustryProjectStationTransfer $left, IndustryProjectStationTransfer $right): int => $left->getLegIndex() <=> $right->getLegIndex());

        return $stored;
    }

    private function storedLeg(IndustryProjectStationTransfer $leg): IndustryProjectStationTransfer
    {
        $this->em->clear();

        return $this->em->find(IndustryProjectStationTransfer::class, $leg->getId());
    }

    private function persistJumpBridgeBetweenStations(User $declaredBy, string $declaredAt): array
    {
        $leg = new IndustryProjectStationTransfer(
            $this->em->getReference(IndustryProject::class, $this->ishtarBatch->getId()),
            $this->em->getReference(User::class, $declaredBy->getId()),
            Uuid::v7(),
            0,
            TransferCarrierKind::JumpBridge,
            new TransferEndpoint(self::PERIMETER_SYSTEM_ID, self::STATION_A_LOCATION_ID),
            new TransferEndpoint(self::AMARR_SYSTEM_ID, self::STATION_B_LOCATION_ID),
            [new TransferLine(self::MORPHITE_TYPE_ID, 20, null, null)],
            new \DateTimeImmutable($declaredAt),
        );
        $this->em->persist($leg);
        $this->em->flush();

        return [$leg];
    }

    private function persistJitaToStationARoute(User $declaredBy, string $declaredAt): array
    {
        $routeId = Uuid::v7();
        $lines = [new TransferLine(self::TRITANIUM_TYPE_ID, 500, null, null), new TransferLine(self::NOCXIUM_TYPE_ID, 40, null, null)];
        $project = $this->em->getReference(IndustryProject::class, $this->ishtarBatch->getId());
        $declarer = $this->em->getReference(User::class, $declaredBy->getId());
        $hubLeg = new IndustryProjectStationTransfer(
            $project,
            $declarer,
            $routeId,
            0,
            TransferCarrierKind::Courier,
            new TransferEndpoint(self::JITA_SYSTEM_ID, null),
            new TransferEndpoint(self::HUB_SYSTEM_ID, null),
            $lines,
            new \DateTimeImmutable($declaredAt),
        );
        $bridgeLeg = new IndustryProjectStationTransfer(
            $project,
            $declarer,
            $routeId,
            1,
            TransferCarrierKind::JumpBridge,
            new TransferEndpoint(self::HUB_SYSTEM_ID, null),
            new TransferEndpoint(self::PERIMETER_SYSTEM_ID, self::STATION_A_LOCATION_ID),
            $lines,
            new \DateTimeImmutable($declaredAt),
        );
        $this->em->persist($hubLeg);
        $this->em->persist($bridgeLeg);
        $this->em->flush();

        return [$hubLeg, $bridgeLeg];
    }

    private function persistStructure(string $name, int $locationId, int $solarSystemId): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName($name)
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setLocationId($locationId)
            ->setSolarSystemId($solarSystemId)
            ->setRigs([]);
        $this->em->persist($structure);

        return $structure;
    }

    private function seedSolarSystemsAndVolumes(): void
    {
        $category = (new InvCategory())->setCategoryId(self::CATEGORY_ID)->setCategoryName('Material');
        $group = (new InvGroup())->setGroupId(self::GROUP_ID)->setGroupName('Mineral')->setCategory($category);
        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        foreach ([$category, $group, $region, $constellation] as $staticRow) {
            $this->em->persist($staticRow);
        }
        $this->em->persist((new InvType())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setGroup($group)->setVolume(0.01));
        $this->em->persist((new InvType())->setTypeId(self::NOCXIUM_TYPE_ID)->setTypeName('Nocxium')->setGroup($group)->setVolume(0.05));
        $this->em->persist((new InvType())->setTypeId(self::MORPHITE_TYPE_ID)->setTypeName('Morphite')->setGroup($group)->setVolume(0.5));
        foreach ([self::JITA_SYSTEM_ID => 'Jita', self::PERIMETER_SYSTEM_ID => 'Perimeter', self::AMARR_SYSTEM_ID => 'Amarr', self::HUB_SYSTEM_ID => 'C-J6MT'] as $solarSystemId => $name) {
            $this->em->persist((new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name)->setConstellation($constellation)->setRegionId(self::REGION_ID));
        }
        $this->em->persist((new IndustryUserSettings())->setUser($this->owner)->setFavoriteManufacturingSystemId(self::PERIMETER_SYSTEM_ID)->setCourierHubSolarSystemId(self::HUB_SYSTEM_ID));
    }

    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }

    private function lineRowsOf(array $leg): array
    {
        return array_map(
            static fn (array $line): array => [
                $line['typeId'],
                $line['typeName'],
                $line['quantity'],
                $line['volume'],
                $line['departed'],
                $line['arrived'],
                $line['inTransit'],
                $line['departureSource'],
                $line['arrivalSource'],
                $line['unknownReason'],
            ],
            $leg['lines'],
        );
    }
}
