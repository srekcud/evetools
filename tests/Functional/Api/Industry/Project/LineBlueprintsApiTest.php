<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectLineBlueprint;
use App\Entity\IndustryStructureConfig;
use App\Entity\OwnedBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\InvGroup;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[Group('issue-1296')]
final class LineBlueprintsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsIshtarBlueprintsAndTheProjectProduct;

    private const int STEP_STATION_ID = 60_003_760;
    private const int OTHER_STATION_ID = 60_008_494;
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;
    private const int VEXOR_TYPE_ID = 626;
    private const int VEXOR_BLUEPRINT_TYPE_ID = 999_296_001;
    private const int CONTAINER_TYPE_ID = 17_366;
    private const int OFFICE_TYPE_ID = 27;
    private const int COPY_QUANTITY = -2;
    private const int ORIGINAL_QUANTITY = -1;
    private const int ORIGINAL_RUNS = -1;
    private const int HIDDEN_DIVISION = 5;
    private const int VISIBLE_DIVISION = 2;
    private const int OWNER_BPC_ITEM_ID = 1_296_000_000_001;
    private const int OWNER_BPC_IN_CONTAINER_ITEM_ID = 1_296_000_000_002;
    private const int OWNER_BPC_ELSEWHERE_ITEM_ID = 1_296_000_000_003;
    private const int OWNER_RAM_BPC_ITEM_ID = 1_296_000_000_004;
    private const int CONTAINER_ITEM_ID = 1_296_000_000_005;
    private const int OWNER_VEXOR_BPC_ITEM_ID = 1_296_000_000_006;
    private const int OWNER_BPO_ITEM_ID = 1_296_000_000_007;
    private const int OWNER_ATTACHED_BPC_ITEM_ID = 1_296_000_000_008;
    private const int MEMBER_BPC_ITEM_ID = 1_296_000_000_009;
    private const int CORPORATION_BPC_ITEM_ID = 1_296_000_000_010;
    private const int CORPORATION_HIDDEN_BPC_ITEM_ID = 1_296_000_000_011;
    private const int OFFICE_ITEM_ID = 1_296_000_000_012;
    private const int UNKNOWN_ITEM_ID = 1_296_999_999_999;
    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e96';
    private const string UNKNOWN_LINE_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e97';
    private const string UNKNOWN_BLUEPRINT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e98';
    private const string EARLIER_ATTACHED_AT = '2026-11-20T10:00:00+00:00';
    private const string LATER_ATTACHED_AT = '2026-11-21T10:00:00+00:00';
    private const string LINE_BLUEPRINT_CHANGED = 'line_blueprint_changed';
    private const array STATION_LABELS = [
        self::STEP_STATION_ID => ['Alpha Station', 'Perimeter'],
        self::OTHER_STATION_ID => ['Elsewhere Station', 'Amarr'],
    ];

    private IndustryStructureConfig $alpha;
    private array $publishedUpdates = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->seedBlueprintTypesAndTheVexorBlueprint();
        $this->seedStepStation();

        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'urn:uuid:published';
        });
        self::getContainer()->set(MercurePublisherService::class, new MercurePublisherService($hub, new NullLogger()));
    }

    public function testProjectWithoutLineBlueprintReadsAnEmptyList(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->lineBlueprintsUri());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson());
    }

    public function testOwnerAttachesACopyInTheStepStationHangarAndTheAnswerIsTheReadOfThatBlueprint(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID));

        self::assertResponseStatusCodeSame(201);
        $read = $this->responseJson();
        self::assertSame(
            ['attachedAt', 'attachedBy', 'blueprintPlace', 'bomLineId', 'id', 'itemId', 'kind', 'materialEfficiency', 'runs', 'state', 'stepPlace', 'timeEfficiency', 'transferToMake', 'typeId', 'typeName', 'unknownReason'],
            $this->sortedKeys($read),
        );
        self::assertSame(
            [$this->idOf($this->ishtarJobLine), self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'bpc', 20, 2, 4, 'Aria Solberg', 'here'],
            [$read['bomLineId'], $read['itemId'], $read['typeId'], $read['typeName'], $read['kind'], $read['runs'], $read['materialEfficiency'], $read['timeEfficiency'], $read['attachedBy'], $read['state']],
        );
        $this->assertNullAt($read, 'unknownReason');
        $this->assertNullAt($read, 'transferToMake');
        self::assertNotNull($read['attachedAt']);
        self::assertSame(
            ['locationId' => self::STEP_STATION_ID, 'name' => 'Alpha Station', 'solarSystemName' => 'Perimeter'],
            $this->ksortedRecursively($read['blueprintPlace']),
        );
        self::assertSame([self::STEP_STATION_ID, 'Alpha'], [$read['stepPlace']['locationId'], $read['stepPlace']['name']]);

        $stored = $this->storedBlueprints();
        self::assertSame([self::OWNER_BPC_ITEM_ID], array_map(static fn (IndustryProjectLineBlueprint $blueprint): int => $blueprint->getItemId(), $stored));
        self::assertSame($read['id'], $stored[0]->getId()->toRfc4122());
        self::assertSame($this->idOf($this->ishtarJobLine), $stored[0]->getBomLine()->getId()->toRfc4122());
        self::assertSame($this->owner->getId()->toRfc4122(), $stored[0]->getAttachedBy()?->getId()->toRfc4122());
    }

    public function testListReadsEveryBlueprintOfTheProjectOrderedByAttachmentWithNoRunsForAnOriginal(): void
    {
        $this->persistBlueprint(self::OWNER_BPO_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::ORIGINAL_QUANTITY, self::ORIGINAL_RUNS, 10, 20, $this->member);
        $this->persistContainerAt(self::CONTAINER_ITEM_ID, $this->member, self::STEP_STATION_ID);
        $this->placeInContainer(self::OWNER_BPO_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->member, self::CONTAINER_ITEM_ID);
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->attach($this->ishtarJobLine, self::OWNER_BPO_ITEM_ID, $this->member, self::LATER_ATTACHED_AT);
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->member);
        $this->request('GET', $this->lineBlueprintsUri());

        self::assertResponseStatusCodeSame(200);
        $blueprints = $this->responseJson();
        self::assertSame(
            [
                [self::OWNER_BPC_ITEM_ID, 'bpc', 20, 2, 4, 'Aria Solberg', 'here'],
                [self::OWNER_BPO_ITEM_ID, 'bpo', null, 10, 20, 'Brann Holt', 'here'],
            ],
            array_map(static fn (array $blueprint): array => [$blueprint['itemId'], $blueprint['kind'], $blueprint['runs'], $blueprint['materialEfficiency'], $blueprint['timeEfficiency'], $blueprint['attachedBy'], $blueprint['state']], $blueprints),
        );
        $this->assertNullAt($blueprints[1], 'runs');
        $this->assertNullAt($blueprints[1], 'unknownReason');
        $this->assertNullAt($blueprints[1], 'transferToMake');
    }

    public function testCopyInAnotherStationReadsElsewhereWithTheTransferToMakeToTheStepStation(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 50, 8, 16, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::OTHER_STATION_ID);
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ELSEWHERE_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->member);
        $this->request('GET', $this->lineBlueprintsUri());

        self::assertResponseStatusCodeSame(200);
        $blueprint = $this->responseJson()[0];
        self::assertSame(['elsewhere', null], [$blueprint['state'], $blueprint['unknownReason']]);
        self::assertSame(
            ['locationId' => self::OTHER_STATION_ID, 'name' => 'Elsewhere Station', 'solarSystemName' => 'Amarr'],
            $this->ksortedRecursively($blueprint['blueprintPlace']),
        );
        self::assertSame($this->expectedTransferToMake(self::OWNER_BPC_ELSEWHERE_ITEM_ID), $this->ksortedRecursively($blueprint['transferToMake']));
    }

    public function testCorporationCopyInAVisibleDivisionAttachesAndReadsHere(): void
    {
        $this->connectCorporationWithVisibleDivision();
        $this->persistCorporationBlueprint(self::CORPORATION_BPC_ITEM_ID, self::VISIBLE_DIVISION, 30, 6, 12);
        $this->persistOfficeAndCorporationAsset(self::CORPORATION_BPC_ITEM_ID, self::VISIBLE_DIVISION);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::CORPORATION_BPC_ITEM_ID));

        self::assertResponseStatusCodeSame(201);
        $read = $this->responseJson();
        self::assertSame(
            [self::CORPORATION_BPC_ITEM_ID, 'bpc', 30, 6, 12, 'here'],
            [$read['itemId'], $read['kind'], $read['runs'], $read['materialEfficiency'], $read['timeEfficiency'], $read['state']],
        );
        $this->assertNullAt($read, 'transferToMake');
    }

    public function testCorporationCopyInAHiddenDivisionIsRefusedWithBlueprintNotVisible(): void
    {
        $this->connectCorporationWithVisibleDivision();
        $this->persistCorporationBlueprint(self::CORPORATION_HIDDEN_BPC_ITEM_ID, self::HIDDEN_DIVISION, 40, 7, 14);
        $this->persistOfficeAndCorporationAsset(self::CORPORATION_HIDDEN_BPC_ITEM_ID, self::HIDDEN_DIVISION);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::CORPORATION_HIDDEN_BPC_ITEM_ID));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([422, 'blueprint_not_visible'], $this->errorDetail());
        self::assertSame([], $this->storedBlueprints());
    }

    public function testItemOfAnotherPilotOrUnknownToTheSyncIsRefusedWithBlueprintNotVisible(): void
    {
        $this->persistBlueprint(self::MEMBER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 11, 3, 6, $this->member);
        $this->placeInStation(self::MEMBER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->member, self::STEP_STATION_ID);
        $this->em->flush();

        foreach ([self::MEMBER_BPC_ITEM_ID, self::UNKNOWN_ITEM_ID] as $itemId) {
            $this->actAs($this->owner);
            $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, $itemId));

            self::assertResponseStatusCodeSame(422);
            self::assertSame([422, 'blueprint_not_visible'], $this->errorDetail());
        }
        self::assertSame([], $this->storedBlueprints());
    }

    public function testBlueprintOfAnotherProductIsRefusedWithBlueprintDoesNotFitLine(): void
    {
        $this->persistBlueprint(self::OWNER_VEXOR_BPC_ITEM_ID, self::VEXOR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 15, 4, 8, $this->owner);
        $this->placeInStation(self::OWNER_VEXOR_BPC_ITEM_ID, self::VEXOR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::OWNER_VEXOR_BPC_ITEM_ID));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([422, 'blueprint_does_not_fit_line'], $this->errorDetail());
        self::assertSame([], $this->storedBlueprints());
    }

    public function testCopyAndInventionLinesAreRefusedWithLineNotBlueprintStep(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $copyLine = $this->addJobLine($this->ishtarBatch, self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'component', 1, 1, 0, 0, self::ISHTAR_TYPE_ID)->setActivityType(IndustryProjectBomLine::ACTIVITY_COPY);
        $inventionLine = $this->addJobLine($this->ishtarBatch, self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'component', 1, 1, 0, 0, self::ISHTAR_TYPE_ID)->setActivityType(IndustryProjectBomLine::ACTIVITY_INVENTION);
        $this->em->flush();

        foreach ([$copyLine, $inventionLine] as $line) {
            $this->actAs($this->owner);
            $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($line, self::OWNER_BPC_ITEM_ID));

            self::assertResponseStatusCodeSame(422);
            self::assertSame([422, 'line_not_blueprint_step'], $this->errorDetail());
        }
        self::assertSame([], $this->storedBlueprints());
    }

    public function testSameItemOnASecondLineOfTheProjectIsRefusedWith409AndTheSecondLineStaysEmpty(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $secondIshtarLine = $this->addJobLine($this->ishtarBatch, self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 1, 1, 4, 8, null);
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($secondIshtarLine, self::OWNER_BPC_ITEM_ID));
        self::assertResponseStatusCodeSame(409);

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID));
        self::assertResponseStatusCodeSame(409);

        $stored = $this->storedBlueprints();
        self::assertSame([[self::OWNER_BPC_ITEM_ID, $this->idOf($this->ishtarJobLine)]], array_map(static fn (IndustryProjectLineBlueprint $blueprint): array => [$blueprint->getItemId(), $blueprint->getBomLine()->getId()->toRfc4122()], $stored));
    }

    public function testAttachingAndDetachingOnACompletedProjectAreRefusedWith409(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->persistBlueprint(self::OWNER_ATTACHED_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 12, 1, 2, $this->owner);
        $this->placeInStation(self::OWNER_ATTACHED_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $attached = $this->attach($this->ishtarJobLine, self::OWNER_ATTACHED_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);
        $this->ishtarBatch->setStatus(ProjectStatus::Completed);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID));
        self::assertResponseStatusCodeSame(409);

        $this->actAs($this->owner);
        $this->request('DELETE', $this->lineBlueprintUri($attached));
        self::assertResponseStatusCodeSame(409);

        self::assertSame([self::OWNER_ATTACHED_BPC_ITEM_ID], array_map(static fn (IndustryProjectLineBlueprint $blueprint): int => $blueprint->getItemId(), $this->storedBlueprints()));
    }

    #[DataProvider('refusedAttachments')]
    public function testRefusedAttachmentBodyIsA422AndStoresNothing(array $overrides, array $removedKeys): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->em->flush();
        $body = array_diff_key(array_replace($this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID), $overrides), array_flip($removedKeys));

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedBlueprints());
    }

    public static function refusedAttachments(): iterable
    {
        yield 'without bomLineId' => [[], ['bomLineId']];
        yield 'without itemId' => [[], ['itemId']];
        yield 'bomLineId that is not a uuid' => [['bomLineId' => 'abc'], []];
        yield 'itemId zero' => [['itemId' => 0], []];
        yield 'itemId negative' => [['itemId' => -5], []];
        yield 'itemId that is not an integer' => [['itemId' => 'abc'], []];
        yield 'itemId beyond int8' => [['itemId' => 9_223_372_036_854_775_807 + 1], []];
    }

    public function testLineOfAnotherProjectOrUnknownIsNotFoundOnAttach(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID));
        self::assertResponseStatusCodeSame(201);

        foreach ([$this->idOf($this->hecateTritaniumLine), self::UNKNOWN_LINE_ID] as $lineId) {
            $this->actAs($this->owner);
            $this->request('POST', $this->lineBlueprintsUri(), ['bomLineId' => $lineId, 'itemId' => self::OWNER_BPC_ITEM_ID]);

            self::assertResponseStatusCodeSame(404);
        }
        self::assertCount(1, $this->storedBlueprints());
    }

    public function testBlueprintAttachedThenDeletedBySyncReadsUnknownNotSyncedWithNullEfficienciesAndRuns(): void
    {
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->member);
        $this->request('GET', $this->lineBlueprintsUri());

        self::assertResponseStatusCodeSame(200);
        $blueprint = $this->responseJson()[0];
        self::assertSame(['unknown', 'blueprint_not_synced', self::OWNER_BPC_ITEM_ID], [$blueprint['state'], $blueprint['unknownReason'], $blueprint['itemId']]);
        foreach (['runs', 'materialEfficiency', 'timeEfficiency', 'transferToMake'] as $key) {
            $this->assertNullAt($blueprint, $key);
        }
    }

    public function testLineWithoutPlannedStationReadsUnknownStepStationUnresolvedWithoutTransfer(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 50, 8, 16, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::OTHER_STATION_ID);
        $this->ishtarJobLine->setPlannedStructureId(null);
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ELSEWHERE_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->member);
        $this->request('GET', $this->lineBlueprintsUri());

        self::assertResponseStatusCodeSame(200);
        $blueprint = $this->responseJson()[0];
        self::assertSame(['unknown', 'step_station_unresolved'], [$blueprint['state'], $blueprint['unknownReason']]);
        $this->assertNullAt($blueprint, 'transferToMake');
    }

    public function testBlueprintWithoutAnyAssetReadsUnknownAssetNotFound(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->member);
        $this->request('GET', $this->lineBlueprintsUri());

        self::assertResponseStatusCodeSame(200);
        $blueprint = $this->responseJson()[0];
        self::assertSame(['unknown', 'asset_not_found', 20, 2, 4], [$blueprint['state'], $blueprint['unknownReason'], $blueprint['runs'], $blueprint['materialEfficiency'], $blueprint['timeEfficiency']]);
        self::assertSame(['locationId' => null, 'name' => null, 'solarSystemName' => null], $this->ksortedRecursively($blueprint['blueprintPlace']));
        $this->assertNullAt($blueprint, 'transferToMake');
    }

    public function testAttachingAndOwnerDetachingPublishALineBlueprintChangedEventOnTheProjectTopic(): void
    {
        $this->persistBlueprint(self::MEMBER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 11, 3, 6, $this->member);
        $this->placeInStation(self::MEMBER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->member, self::STEP_STATION_ID);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('POST', $this->lineBlueprintsUri(), $this->attachment($this->ishtarJobLine, self::MEMBER_BPC_ITEM_ID));
        self::assertResponseStatusCodeSame(201);
        $attached = $this->responseJson();
        self::assertSame('Brann Holt', $attached['attachedBy']);

        $this->actAs($this->owner);
        $this->request('DELETE', $this->lineBlueprintsUri().'/'.$attached['id']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->storedBlueprints());

        $topic = '/group-project/'.$this->ishtarBatch->getId()->toRfc4122().'/events';
        $lineId = $this->idOf($this->ishtarJobLine);
        $events = array_values(array_filter(array_map(
            static fn (Update $update): array => ['topic' => $update->getTopics()[0], 'payload' => json_decode($update->getData(), true)],
            $this->publishedUpdates,
        ), static fn (array $event): bool => self::LINE_BLUEPRINT_CHANGED === ($event['payload']['action'] ?? null)));

        self::assertSame(
            [
                [$topic, ['action' => 'attached', 'itemId' => self::MEMBER_BPC_ITEM_ID, 'lineId' => $lineId]],
                [$topic, ['action' => 'detached', 'itemId' => self::MEMBER_BPC_ITEM_ID, 'lineId' => $lineId]],
            ],
            array_map(fn (array $event): array => [$event['topic'], $this->ksortedRecursively($event['payload']['data'])], $events),
        );
    }

    public function testMemberWhoIsNeitherTheAttacherNorTheOwnerCannotDetach(): void
    {
        $attached = $this->attach($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->member);
        $this->request('DELETE', $this->lineBlueprintUri($attached));

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->storedBlueprints());
        self::assertSame([], array_filter($this->publishedUpdates, static fn (Update $update): bool => str_contains($update->getData(), self::LINE_BLUEPRINT_CHANGED)));
    }

    public function testDetachingABlueprintOfAnotherProjectOrUnknownIsNotFound(): void
    {
        $foreign = $this->attach($this->hecateTritaniumLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);
        $own = $this->attach($this->ishtarJobLine, self::OWNER_ATTACHED_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->owner);
        $this->request('DELETE', $this->lineBlueprintUri($own));
        self::assertResponseStatusCodeSame(204);

        foreach ([$foreign->getId()->toRfc4122(), self::UNKNOWN_BLUEPRINT_ID] as $blueprintId) {
            $this->actAs($this->owner);
            $this->request('DELETE', $this->lineBlueprintsUri().'/'.$blueprintId);

            self::assertResponseStatusCodeSame(404);
        }
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(IndustryProjectLineBlueprint::class)->findBy(['project' => $this->hecateBatch->getId()]));
    }

    public function testCandidatesAreTheVisibleBlueprintsOfTheProductOfEachLineNotYetAttachedOrderedByLineThenHereBeforeElsewhereThenRunsDescending(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->persistContainerAt(self::CONTAINER_ITEM_ID, $this->owner, self::STEP_STATION_ID);
        $this->persistBlueprint(self::OWNER_BPC_IN_CONTAINER_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 35, 6, 12, $this->owner);
        $this->placeInContainer(self::OWNER_BPC_IN_CONTAINER_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::CONTAINER_ITEM_ID);
        $this->persistBlueprint(self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 50, 8, 16, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::OTHER_STATION_ID);
        $this->persistBlueprint(self::OWNER_RAM_BPC_ITEM_ID, self::RAM_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 9, 1, 2, $this->owner);
        $this->persistBlueprint(self::OWNER_ATTACHED_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 12, 5, 10, $this->owner);
        $this->placeInStation(self::OWNER_ATTACHED_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->persistBlueprint(self::OWNER_VEXOR_BPC_ITEM_ID, self::VEXOR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 15, 4, 8, $this->owner);
        $this->placeInStation(self::OWNER_VEXOR_BPC_ITEM_ID, self::VEXOR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->persistBlueprint(self::MEMBER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 11, 3, 6, $this->member);
        $this->placeInStation(self::MEMBER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->member, self::STEP_STATION_ID);
        $this->attach($this->ishtarJobLine, self::OWNER_ATTACHED_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);

        $this->actAs($this->owner);
        $this->request('GET', $this->candidatesUri());

        self::assertResponseStatusCodeSame(200);
        $candidates = $this->responseJson();
        $ishtarLineId = $this->idOf($this->ishtarJobLine);
        $ramLineId = $this->idOf($this->ramJobLine);
        self::assertSame(
            [
                [$ishtarLineId, self::OWNER_BPC_IN_CONTAINER_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'bpc', 35, 6, 12, 'here', null],
                [$ishtarLineId, self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'bpc', 20, 2, 4, 'here', null],
                [$ishtarLineId, self::OWNER_BPC_ELSEWHERE_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'bpc', 50, 8, 16, 'elsewhere', null],
                [$ramLineId, self::OWNER_RAM_BPC_ITEM_ID, self::RAM_BLUEPRINT_TYPE_ID, 'R.A.M.- Starship Tech Blueprint', 'bpc', 9, 1, 2, 'unknown', 'asset_not_found'],
            ],
            array_map(static fn (array $candidate): array => [$candidate['bomLineId'], $candidate['itemId'], $candidate['typeId'], $candidate['typeName'], $candidate['kind'], $candidate['runs'], $candidate['materialEfficiency'], $candidate['timeEfficiency'], $candidate['state'], $candidate['unknownReason']], $candidates),
        );
        foreach ([0, 1, 3] as $withoutTransfer) {
            $this->assertNullAt($candidates[$withoutTransfer], 'transferToMake');
        }
        self::assertSame($this->expectedTransferToMake(self::OWNER_BPC_ELSEWHERE_ITEM_ID), $this->ksortedRecursively($candidates[2]['transferToMake']));
    }

    public function testPilotOutsideTheProjectAndPendingMemberAreRefusedReadingCandidatesAndAttaching(): void
    {
        $this->persistBlueprint(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, self::COPY_QUANTITY, 20, 2, 4, $this->owner);
        $this->placeInStation(self::OWNER_BPC_ITEM_ID, self::ISHTAR_BLUEPRINT_TYPE_ID, $this->owner, self::STEP_STATION_ID);
        $this->em->flush();

        foreach ([$this->outsider, $this->pendingMember] as $user) {
            foreach ([['GET', $this->lineBlueprintsUri()], ['GET', $this->candidatesUri()], ['POST', $this->lineBlueprintsUri()]] as [$method, $uri]) {
                $this->actAs($user);
                $this->request($method, $uri, 'POST' === $method ? $this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID) : null);

                self::assertResponseStatusCodeSame(403);
                self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
            }
        }
        self::assertSame([], $this->storedBlueprints());
    }

    public function testLineBlueprintsAreRefusedWithoutConnectedUserAndOnAnUnknownProject(): void
    {
        foreach ([['GET', $this->lineBlueprintsUri()], ['GET', $this->candidatesUri()], ['POST', $this->lineBlueprintsUri()]] as [$method, $uri]) {
            $this->request($method, $uri, 'POST' === $method ? $this->attachment($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID) : null);
            self::assertResponseStatusCodeSame(401);
        }

        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/line-blueprints');

            self::assertResponseStatusCodeSame(404);
            self::assertSame([404, 'Project not found'], $this->errorDetail());
        }
    }

    public function testRemovingALineFromThePlanRemovesItsStepBlueprintsAndKeepsTheOthers(): void
    {
        $this->attach($this->ishtarJobLine, self::OWNER_BPC_ITEM_ID, $this->owner, self::EARLIER_ATTACHED_AT);
        $this->attach($this->ramJobLine, self::OWNER_RAM_BPC_ITEM_ID, $this->owner, self::LATER_ATTACHED_AT);
        $ramLineId = $this->ramJobLine->getId();
        $projectId = $this->ishtarBatch->getId();
        $this->em->clear();

        $project = $this->em->find(IndustryProject::class, $projectId);
        $project->removeBomItem($this->em->find(IndustryProjectBomLine::class, $ramLineId));
        $this->em->flush();

        self::assertSame([self::OWNER_BPC_ITEM_ID], array_map(static fn (IndustryProjectLineBlueprint $blueprint): int => $blueprint->getItemId(), $this->storedBlueprints()));
    }

    private function lineBlueprintsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/line-blueprints';
    }

    private function candidatesUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/line-blueprint-candidates';
    }

    private function lineBlueprintUri(IndustryProjectLineBlueprint $blueprint): string
    {
        return $this->lineBlueprintsUri().'/'.$blueprint->getId()->toRfc4122();
    }

    private function attachment(IndustryProjectBomLine $line, int $itemId): array
    {
        return ['bomLineId' => $this->idOf($line), 'itemId' => $itemId];
    }

    private function expectedTransferToMake(int $itemId): array
    {
        return $this->ksortedRecursively([
            'typeId' => self::ISHTAR_BLUEPRINT_TYPE_ID,
            'typeName' => 'Ishtar Blueprint',
            'itemId' => $itemId,
            'quantity' => 1,
            'from' => ['locationId' => self::OTHER_STATION_ID, 'name' => 'Elsewhere Station', 'solarSystemName' => 'Amarr'],
            'to' => ['locationId' => self::STEP_STATION_ID, 'name' => 'Alpha', 'solarSystemId' => self::PERIMETER_SYSTEM_ID, 'solarSystemName' => 'Perimeter'],
        ]);
    }

    private function assertNullAt(array $row, string $key): void
    {
        self::assertArrayHasKey($key, $row);
        self::assertNull($row[$key]);
    }

    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }

    private function ksortedRecursively(array $entry): array
    {
        ksort($entry);
        foreach ($entry as $key => $value) {
            if (\is_array($value)) {
                $entry[$key] = $this->ksortedRecursively($value);
            }
        }

        return $entry;
    }

    private function storedBlueprints(): array
    {
        $this->em->clear();

        return $this->em->getRepository(IndustryProjectLineBlueprint::class)->findBy(['project' => $this->ishtarBatch->getId()], ['attachedAt' => 'ASC']);
    }

    private function attach(IndustryProjectBomLine $line, int $itemId, User $attachedBy, string $attachedAt): IndustryProjectLineBlueprint
    {
        $this->em->flush();
        $blueprint = new IndustryProjectLineBlueprint($line, $itemId, $attachedBy, new \DateTimeImmutable($attachedAt));
        $this->em->persist($blueprint);
        $this->em->flush();

        return $blueprint;
    }

    private function persistBlueprint(int $itemId, int $blueprintTypeId, int $quantity, int $runs, int $materialEfficiency, int $timeEfficiency, User $holder): void
    {
        $this->em->persist((new OwnedBlueprint())
            ->setItemId($itemId)
            ->setTypeId($blueprintTypeId)
            ->setLocationId(self::STEP_STATION_ID)
            ->setLocationFlag('Hangar')
            ->setQuantity($quantity)
            ->setMaterialEfficiency($materialEfficiency)
            ->setTimeEfficiency($timeEfficiency)
            ->setRuns($runs)
            ->setCharacter($holder->getMainCharacter())
            ->setSyncedAt(new \DateTimeImmutable('2026-11-20T08:00:00+00:00')));
    }

    private function persistCorporationBlueprint(int $itemId, int $division, int $runs, int $materialEfficiency, int $timeEfficiency): void
    {
        $this->em->persist((new OwnedBlueprint())
            ->setItemId($itemId)
            ->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
            ->setLocationId(self::OFFICE_ITEM_ID)
            ->setLocationFlag('CorpSAG'.$division)
            ->setQuantity(self::COPY_QUANTITY)
            ->setMaterialEfficiency($materialEfficiency)
            ->setTimeEfficiency($timeEfficiency)
            ->setRuns($runs)
            ->setCorporationId(self::OWNER_CORPORATION_ID)
            ->setDivisionNumber($division)
            ->setSyncedAt(new \DateTimeImmutable('2026-11-20T08:00:00+00:00')));
    }

    private function placeInStation(int $itemId, int $blueprintTypeId, User $holder, int $stationId): void
    {
        [$stationName, $solarSystemName] = self::STATION_LABELS[$stationId];
        $this->em->persist($this->personalAsset($itemId, $blueprintTypeId, 'Blueprint', $stationId, 'station', $holder)
            ->setLocationName($stationName)
            ->setSolarSystemName($solarSystemName));
    }

    private function placeInContainer(int $itemId, int $blueprintTypeId, User $holder, int $containerItemId): void
    {
        $this->em->persist($this->personalAsset($itemId, $blueprintTypeId, 'Blueprint', $containerItemId, 'item', $holder)
            ->setLocationName('Container'));
    }

    private function persistContainerAt(int $containerItemId, User $holder, int $stationId): void
    {
        [$stationName, $solarSystemName] = self::STATION_LABELS[$stationId];
        $this->em->persist($this->personalAsset($containerItemId, self::CONTAINER_TYPE_ID, 'Giant Secure Container', $stationId, 'station', $holder)
            ->setLocationName($stationName)
            ->setSolarSystemName($solarSystemName));
    }

    private function personalAsset(int $itemId, int $typeId, string $typeName, int $locationId, string $locationType, User $holder): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity(1)
            ->setLocationId($locationId)
            ->setLocationType($locationType)
            ->setCharacter($holder->getMainCharacter())
            ->setIsCorporationAsset(false);
    }

    private function persistOfficeAndCorporationAsset(int $itemId, int $division): void
    {
        $office = $this->em->getRepository(CachedAsset::class)->findOneBy(['itemId' => self::OFFICE_ITEM_ID]);
        if (null === $office) {
            $this->em->persist((new CachedAsset())
                ->setItemId(self::OFFICE_ITEM_ID)
                ->setTypeId(self::OFFICE_TYPE_ID)
                ->setTypeName('Office')
                ->setQuantity(1)
                ->setLocationId(self::STEP_STATION_ID)
                ->setLocationName('Alpha Station')
                ->setSolarSystemName('Perimeter')
                ->setLocationType('station')
                ->setLocationFlag(EveConstants::OFFICE_LOCATION_FLAG)
                ->setCorporationId(self::OWNER_CORPORATION_ID)
                ->setIsCorporationAsset(true));
        }
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
            ->setTypeName('Blueprint')
            ->setQuantity(1)
            ->setLocationId(self::OFFICE_ITEM_ID)
            ->setLocationName('Office')
            ->setLocationType('item')
            ->setLocationFlag('CorpSAG'.$division)
            ->setCorporationId(self::OWNER_CORPORATION_ID)
            ->setIsCorporationAsset(true));
        $this->em->flush();
    }

    private function connectCorporationWithVisibleDivision(): void
    {
        $token = (new EveToken())
            ->setAccessToken('access-token')
            ->setRefreshTokenEncrypted('encrypted-refresh-token')
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+20 minutes'))
            ->setScopes([EveConstants::CORPORATION_ASSETS_SCOPE, EveConstants::CORPORATION_ROLES_SCOPE]);
        $this->owner->getMainCharacter()?->setEveToken($token);
        $this->em->persist($token);
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('get')->willReturn(['roles' => ['Director']]);
        self::getContainer()->set(EsiClient::class, $esiClient);
        $this->em->persist((new CorpAssetVisibility())
            ->setCorporationId(self::OWNER_CORPORATION_ID)
            ->setVisibleDivisions([self::VISIBLE_DIVISION])
            ->setShareBlueprintsOfHiddenDivisions(false)
            ->setConfiguredBy($this->owner));
        $this->em->flush();
    }

    private function seedBlueprintTypesAndTheVexorBlueprint(): void
    {
        $group = $this->em->find(InvGroup::class, self::SYNTHETIC_GROUP_ID);
        foreach ([
            self::ISHTAR_BLUEPRINT_TYPE_ID => 'Ishtar Blueprint',
            self::RAM_BLUEPRINT_TYPE_ID => 'R.A.M.- Starship Tech Blueprint',
            self::VEXOR_BLUEPRINT_TYPE_ID => 'Vexor Blueprint',
            self::VEXOR_TYPE_ID => 'Vexor',
        ] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group));
        }
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('sde_industry_blueprints', ['type_id' => self::VEXOR_BLUEPRINT_TYPE_ID, 'max_production_limit' => 200]);
        $connection->insert('sde_industry_activities', ['type_id' => self::VEXOR_BLUEPRINT_TYPE_ID, 'activity_id' => self::ACTIVITY_MANUFACTURING, 'time' => 900]);
        $connection->insert('sde_industry_activity_products', [
            'type_id' => self::VEXOR_BLUEPRINT_TYPE_ID,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => self::VEXOR_TYPE_ID,
            'quantity' => 1,
        ]);
    }

    private function seedStepStation(): void
    {
        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        $perimeter = (new MapSolarSystem())->setSolarSystemId(self::PERIMETER_SYSTEM_ID)->setSolarSystemName('Perimeter')->setConstellation($constellation)->setRegionId(self::REGION_ID);
        $this->alpha = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName('Alpha')
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setLocationId(self::STEP_STATION_ID)
            ->setSolarSystemId(self::PERIMETER_SYSTEM_ID)
            ->setRigs([]);
        foreach ([$region, $constellation, $perimeter, $this->alpha] as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();
        $this->ishtarJobLine->setPlannedStructureId($this->alpha->getId()->toRfc4122());
        $this->ramJobLine->setPlannedStructureId($this->alpha->getId()->toRfc4122());
        $this->em->flush();
    }
}
