<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectPurchase;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\IndustryProjectService;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1285')]
final class ProjectWorkStationsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 999_285_001;
    private const int RAM_BLUEPRINT_TYPE_ID = 999_285_002;
    private const int SYNTHETIC_CATEGORY_ID = 999_285_003;
    private const int SYNTHETIC_GROUP_ID = 999_285_004;
    private const int RAM_BASE_QUANTITY_PER_ISHTAR_RUN = 155;
    private const int RAM_UNITS_PER_BLUEPRINT_RUN = 100;
    private const int RAM_TRITANIUM_PER_RUN = 400;
    private const int ISHTAR_TRITANIUM_PER_RUN = 5_000;
    private const float ISHTAR_CUBIC_METERS = 50_000.0;
    private const float RAM_CUBIC_METERS = 0.04;
    private const float TRITANIUM_CUBIC_METERS = 0.01;
    private const float RAM_SELL_PRICE = 1_000.0;
    private const float TRITANIUM_SELL_PRICE = 5.0;
    private const float RAM_ADJUSTED_PRICE = 900.0;
    private const float TRITANIUM_ADJUSTED_PRICE = 4.0;
    private const float JITA_MANUFACTURING_COST_INDEX = 0.05;
    private const float WORKSHOP_FACILITY_TAX_PERCENT = 1.0;
    private const float ANNEX_FACILITY_TAX_PERCENT = 0.5;
    private const float JUMP_BRIDGE_ISK_PER_CUBIC_METER = 50.0;
    private const float COURIER_ISK_PER_CUBIC_METER = 800.0;
    private const string WORKSHOP_NAME = 'Jita Raitaru';
    private const string ANNEX_NAME = 'Jita Raitaru Annex';
    private const float CENTS = 0.01;
    private const int ISHTAR_JOB_ID = 128_500_001;

    private const array RESOURCE_KEYS = ['courierHubSolarSystemId', 'courierHubSolarSystemName', 'courierIskPerCubicMeter', 'id', 'jumpBridgeIskPerCubicMeter', 'structuresToSpecify', 'workTypes'];

    private const array WORK_TYPE_KEYS = [
        'activity', 'choiceStale', 'chosenStructureId', 'costsCompared', 'jobs', 'lockReason', 'locked', 'recommendedStructureId', 'rows', 'runs',
        'savingIfRecommended', 'storedRecommendedStructureId', 'workType',
    ];

    private const array ROW_KEYS = [
        'delta', 'facilityTaxPercent', 'inFavoriteSystem', 'installationCost', 'jumps', 'kind', 'materialBonusPercent', 'materialsCost', 'missingData',
        'name', 'noRigForProduct', 'productCategory', 'rigs', 'solarSystemId', 'solarSystemName', 'structureId', 'totalCost', 'transportCost', 'transportLegs',
    ];

    private const array LEG_KEYS = ['cost', 'cubicMeters', 'fromStructureId', 'kind', 'toStructureId'];

    private IndustryStructureConfig $workshop;
    private IndustryStructureConfig $annex;
    private IndustryProject $soloIshtar;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->configureTheStructuresOfTheOwnerInJita();
        $this->seedAdjustedPrices([
            self::RAM_STARSHIP_TECH_TYPE_ID => self::RAM_ADJUSTED_PRICE,
            self::TRITANIUM_TYPE_ID => self::TRITANIUM_ADJUSTED_PRICE,
        ]);
        $this->seedJitaCostIndex('manufacturing', self::JITA_MANUFACTURING_COST_INDEX);
        $this->seedJitaSellOrderBooks([
            self::RAM_STARSHIP_TECH_TYPE_ID => [['price' => self::RAM_SELL_PRICE, 'volume' => 100_000]],
            self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_SELL_PRICE, 'volume' => 1_000_000]],
        ]);
        $this->soloIshtar = $this->persistProject('Solo Ishtar');
        $this->soloIshtar->setStatus(ProjectStatus::Draft)->enrolOwner();
        $this->soloIshtar->addItem((new IndustryProjectProduct())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setMeLevel(4)
            ->setTeLevel(8)
            ->setRuns(self::ISHTAR_RUNS)
            ->setSortOrder(0));
        $this->em->flush();
        self::getContainer()->get(IndustryProjectService::class)->buildBom($this->soloIshtar, $this->owner);
        $this->em->flush();
    }

    public function testAcceptedParticipantReadsTheCourierSettingsAndOneEntryPerTypeOfWorkOfThePlanWithTheirKeys(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(self::RESOURCE_KEYS, $keys);
        self::assertSame(
            [$this->ishtarBatch->getId()->toRfc4122(), EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID, self::COURIER_ISK_PER_CUBIC_METER, self::JUMP_BRIDGE_ISK_PER_CUBIC_METER],
            [$body['id'], $body['courierHubSolarSystemId'], $body['courierIskPerCubicMeter'], $body['jumpBridgeIskPerCubicMeter']],
        );
        self::assertSame(['components', 'final_products'], array_column($body['workTypes'], 'workType'));
        foreach ($body['workTypes'] as $workType) {
            $workTypeKeys = array_keys($workType);
            sort($workTypeKeys);
            self::assertSame(self::WORK_TYPE_KEYS, $workTypeKeys);
            $rowKeys = array_keys($workType['rows'][0] ?? []);
            sort($rowKeys);
            self::assertSame(self::ROW_KEYS, $rowKeys);
            $legKeys = array_keys($workType['rows'][0]['transportLegs'][0] ?? []);
            sort($legKeys);
            self::assertSame(self::LEG_KEYS, $legKeys);
        }
    }

    #[Group('issue-1343')]
    public function testOwnerWithoutHullToSpecifyStructureExposesAnEmptyListOfStructuresToSpecify(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('structuresToSpecify', $this->responseJson());
        self::assertSame([], $this->responseJson()['structuresToSpecify']);
    }

    #[Group('issue-1343')]
    public function testHullToSpecifyStructuresOfTheOwnerAreListedAndLeftOutOfTheComparisonWithoutFailingThePlan(): void
    {
        $legacyComplex = $this->legacyStructureOfTheOwner('Legacy Complex', 'engineering_complex');
        $legacyRefinery = $this->legacyStructureOfTheOwner('Legacy Refinery', 'refinery');
        $this->legacyStructureOfTheOwner('Deleted Legacy', 'engineering_complex')->setIsDeleted(true);
        $foreignLegacy = (new IndustryStructureConfig())
            ->setUser($this->member)
            ->setName('Foreign Legacy')
            ->setStructureType('engineering_complex')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setRigs([]);
        $this->em->persist($foreignLegacy);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $listed = $this->responseJson()['structuresToSpecify'];
        usort($listed, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        self::assertSame(
            [
                ['id' => $legacyComplex->getId()->toRfc4122(), 'name' => 'Legacy Complex', 'structureType' => 'engineering_complex'],
                ['id' => $legacyRefinery->getId()->toRfc4122(), 'name' => 'Legacy Refinery', 'structureType' => 'refinery'],
            ],
            $listed,
        );
        $comparedNames = array_column($this->workTypeOf('final_products')['rows'], 'name');
        sort($comparedNames);
        self::assertSame([self::WORKSHOP_NAME, self::ANNEX_NAME], $comparedNames);
    }

    #[Group('issue-1340')]
    public function testCourierHubSolarSystemNameIsReadFromTheSdeSolarSystemsOfTheHub(): void
    {
        $region = (new MapRegion())->setRegionId(10_000_058)->setRegionName('Fountain');
        $constellation = (new MapConstellation())->setConstellationId(20_000_754)->setConstellationName('Kimotoro')->setRegion($region);
        $hub = (new MapSolarSystem())
            ->setSolarSystemId(EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID)
            ->setSolarSystemName('C-J6MT')
            ->setConstellation($constellation)
            ->setRegionId(10_000_058);
        foreach ([$region, $constellation, $hub] as $staticRow) {
            $this->em->persist($staticRow);
        }
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID, $body['courierHubSolarSystemId']);
        self::assertArrayHasKey('courierHubSolarSystemName', $body);
        self::assertSame('C-J6MT', $body['courierHubSolarSystemName']);
    }

    #[Group('issue-1340')]
    public function testCourierHubSolarSystemNameIsNullWhenTheSdeDoesNotKnowTheHubSystem(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID, $body['courierHubSolarSystemId']);
        self::assertArrayHasKey('courierHubSolarSystemName', $body);
        self::assertNull($body['courierHubSolarSystemName']);
    }

    public function testFinalProductsAreComparedAmongTheStructuresOfTheOwnerWithTheirTravelsAndTheRecommendedOneIsTheCheapest(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $final = $this->workTypeOf('final_products');
        $workshopId = $this->workshop->getId()->toRfc4122();
        $annexId = $this->annex->getId()->toRfc4122();
        self::assertSame(
            ['manufacturing', 1, self::ISHTAR_RUNS, $annexId, null, false, null, null, false, null, true],
            $this->fieldsOf($final, ['activity', 'jobs', 'runs', 'recommendedStructureId', 'chosenStructureId', 'choiceStale', 'storedRecommendedStructureId', 'savingIfRecommended', 'locked', 'lockReason', 'costsCompared']),
        );
        self::assertSame([$annexId, $workshopId], array_column($final['rows'], 'structureId'));
        $annexRow = $final['rows'][0];
        self::assertSame(
            [self::ANNEX_NAME, 'raitaru', EveConstants::JITA_SOLAR_SYSTEM_ID, true, 0.0, self::ANNEX_FACILITY_TAX_PERCENT, [], 360_000.0],
            $this->fieldsOf($annexRow, ['name', 'kind', 'solarSystemId', 'inFavoriteSystem', 'materialBonusPercent', 'facilityTaxPercent', 'missingData', 'materialsCost']),
        );
        self::assertArrayHasKey('jumps', $annexRow);
        self::assertSame(0, $annexRow['jumps']);
        self::assertEqualsWithDelta(30_305.0, $annexRow['installationCost'], self::CENTS);
        self::assertEqualsWithDelta(5_000_000.0, $annexRow['transportCost'], self::CENTS);
        self::assertEqualsWithDelta(5_390_305.0, $annexRow['totalCost'], self::CENTS);
        self::assertEqualsWithDelta(0.0, $annexRow['delta'], self::CENTS);
        $workshopRow = $final['rows'][1];
        self::assertEqualsWithDelta(31_900.0, $workshopRow['installationCost'], self::CENTS);
        self::assertEqualsWithDelta(5_391_900.0, $workshopRow['totalCost'], self::CENTS);
        self::assertEqualsWithDelta(1_595.0, $workshopRow['delta'], self::CENTS);
        $toHub = $this->legOfKind($annexRow, 'to_hub');
        self::assertSame([$annexId, null], [$toHub['fromStructureId'], $toHub['toStructureId']]);
        self::assertEqualsWithDelta(100_000.0, $toHub['cubicMeters'], self::CENTS);
        self::assertEqualsWithDelta(5_000_000.0, $toHub['cost'], self::CENTS);
        $jumpBridge = $this->legOfKind($workshopRow, 'jump_bridge');
        self::assertSame([$annexId, $workshopId], [$jumpBridge['fromStructureId'], $jumpBridge['toStructureId']]);
        self::assertEqualsWithDelta(0.0, $jumpBridge['cost'], self::CENTS);
        $courier = $this->legOfKind($workshopRow, 'courier');
        self::assertSame([null, $workshopId], [$courier['fromStructureId'], $courier['toStructureId']]);
        self::assertEqualsWithDelta(0.0, $courier['cost'], self::CENTS);
    }

    public function testComponentsAreComparedWithTheirOwnRunsAndCosts(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $components = $this->workTypeOf('components');
        self::assertSame([1, 3, $this->annex->getId()->toRfc4122()], [$components['jobs'], $components['runs'], $components['recommendedStructureId']]);
        $annexRow = $components['rows'][0];
        self::assertEqualsWithDelta(6_000.0, $annexRow['materialsCost'], self::CENTS);
        self::assertEqualsWithDelta(456.0, $annexRow['installationCost'], self::CENTS);
        self::assertEqualsWithDelta(0.0, $annexRow['transportCost'], self::CENTS);
        self::assertEqualsWithDelta(6_456.0, $annexRow['totalCost'], self::CENTS);
        self::assertEqualsWithDelta(6_480.0, $components['rows'][1]['totalCost'], self::CENTS);
    }

    public function testStoredChoiceDifferentFromTheRecommendedIsReadWithTheSavingIfTheRecommendedIsUsed(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $annexId = $this->annex->getId()->toRfc4122();
        $this->ishtarBatch->setWorkStations(['final_products' => ['structureId' => $workshopId, 'recommendedStructureId' => $annexId]]);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $final = $this->workTypeOf('final_products');
        self::assertSame([$annexId, $workshopId, $annexId, false], $this->fieldsOf($final, ['recommendedStructureId', 'chosenStructureId', 'storedRecommendedStructureId', 'choiceStale']));
        self::assertEqualsWithDelta(1_595.0, $final['savingIfRecommended'], self::CENTS);
        self::assertSame([null, false, null], $this->fieldsOf($this->workTypeOf('components'), ['chosenStructureId', 'choiceStale', 'savingIfRecommended']));
    }

    public function testStoredStructureThatNoLongerExistsIsStaleNotChosenAndThePlanStaysAutomatic(): void
    {
        $deletedId = Uuid::v7()->toRfc4122();
        $this->ishtarBatch->setWorkStations(['final_products' => ['structureId' => $deletedId, 'recommendedStructureId' => $this->annex->getId()->toRfc4122()]]);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $final = $this->workTypeOf('final_products');
        self::assertSame([null, true, null, $this->annex->getId()->toRfc4122()], $this->fieldsOf($final, ['chosenStructureId', 'choiceStale', 'savingIfRecommended', 'recommendedStructureId']));
    }

    public function testTypeOfWorkWithALinkedJobIsLockedForJobLaunched(): void
    {
        $this->ramJobLine->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(self::RAM_RUNS)->setStatus('active')->setManual(true));
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([true, 'job_launched'], $this->fieldsOf($this->workTypeOf('components'), ['locked', 'lockReason']));
        self::assertSame([false, null], $this->fieldsOf($this->workTypeOf('final_products'), ['locked', 'lockReason']));
    }

    public function testPurchaseOnAMaterialConsumedByTheJobsOfATypeLocksItForPurchaseMade(): void
    {
        $this->tritaniumLine->addPurchase($this->purchaseOf(self::TRITANIUM_TYPE_ID, 1_000, 4.4));
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([true, 'purchase_made'], $this->fieldsOf($this->workTypeOf('components'), ['locked', 'lockReason']));
        self::assertSame([true, 'purchase_made'], $this->fieldsOf($this->workTypeOf('final_products'), ['locked', 'lockReason']));
    }

    public function testPurchaseOnAMaterialThePlanDoesNotConsumeLocksNothing(): void
    {
        $this->nocxiumLine->addPurchase($this->purchaseOf(self::NOCXIUM_TYPE_ID, 600, 900.0));
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([false, null, false, null], [
            ...$this->fieldsOf($this->workTypeOf('components'), ['locked', 'lockReason']),
            ...$this->fieldsOf($this->workTypeOf('final_products'), ['locked', 'lockReason']),
        ]);
    }

    public function testPilotOfAnotherCorporationIsRefusedTheWorkStations(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->workStationsUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testWorkStationsOfAnUnknownProjectAreNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->workStationsUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    public function testOwnerChoosesTheStationOfATypeAndTheChoiceIsStoredWithTheRecommendedOneAndAssignedToTheLinesOfThePlan(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $annexId = $this->annex->getId()->toRfc4122();

        $this->patchWorkStations(['components' => $workshopId]);

        self::assertResponseStatusCodeSame(200);
        $expected = ['components' => ['structureId' => $workshopId, 'recommendedStructureId' => $annexId]];
        self::assertSame($expected, $this->responseJson()['workStations']);
        self::assertSame($expected, $this->storedWorkStations());
        $this->em->clear();
        $ramLine = $this->bomLineOf(self::RAM_STARSHIP_TECH_TYPE_ID);
        self::assertSame([$workshopId, 'assigned'], [$ramLine->getPlannedStructureId(), $ramLine->getPlannedStructureStatus()]);
        $ishtarLine = $this->bomLineOf(self::ISHTAR_TYPE_ID);
        self::assertSame('best_in_favorite_system', $ishtarLine->getPlannedStructureStatus());
    }

    public function testProjectWithoutChoiceExposesNoWorkStation(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->soloIshtar));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertArrayHasKey('workStations', $body);
        self::assertSame([], $body['workStations']);
    }

    public function testChoiceOfAnotherTypeLeavesTheStoredChoicesOfTheOthersUntouched(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $annexId = $this->annex->getId()->toRfc4122();
        $this->patchWorkStations(['components' => $workshopId]);
        self::assertResponseStatusCodeSame(200);

        $this->patchWorkStations(['final_products' => $workshopId], reseedingThePrices: true);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                'components' => ['structureId' => $workshopId, 'recommendedStructureId' => $annexId],
                'final_products' => ['structureId' => $workshopId, 'recommendedStructureId' => $annexId],
            ],
            $this->storedWorkStations(),
        );
    }

    public function testNullRemovesTheChoiceOfATypeAndItsRecommendedStructure(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $this->patchWorkStations(['components' => $workshopId, 'final_products' => $workshopId]);
        self::assertResponseStatusCodeSame(200);

        $this->patchWorkStations(['components' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['final_products'], array_keys($this->responseJson()['workStations']));
        self::assertSame(['final_products'], array_keys($this->storedWorkStations()));
        $this->em->clear();
        self::assertSame('best_in_favorite_system', $this->bomLineOf(self::RAM_STARSHIP_TECH_TYPE_ID)->getPlannedStructureStatus());
    }

    public function testNullForATypeWithoutChoiceIsWithoutEffect(): void
    {
        $this->patchWorkStations(['components' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson()['workStations']);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testPatchWithoutWorkStationsLeavesTheChoicesUnchanged(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $this->patchWorkStations(['components' => $workshopId]);
        self::assertResponseStatusCodeSame(200);
        $stored = $this->storedWorkStations();
        self::assertNotSame([], $stored);

        $this->actAs($this->owner);
        $this->request('PATCH', $this->projectUri($this->soloIshtar), ['notes' => 'Keep the annex idle']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, $this->responseJson()['workStations']);
        self::assertSame($stored, $this->storedWorkStations());
    }

    public function testSameValueAgainIsWithoutEffectEvenWhenTheTypeIsLocked(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $this->patchWorkStations(['components' => $workshopId]);
        self::assertResponseStatusCodeSame(200);
        $stored = $this->storedWorkStations();
        self::assertNotSame([], $stored);
        $this->lockTheComponentsOfTheSoloProject();

        $this->patchWorkStations(['components' => $workshopId]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($stored, $this->storedWorkStations());
    }

    public function testLockedTypeRefusesAnotherStructureAndRemovalAndKeepsItsChoice(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();
        $this->patchWorkStations(['components' => $workshopId]);
        self::assertResponseStatusCodeSame(200);
        $stored = $this->storedWorkStations();
        self::assertNotSame([], $stored);
        $this->lockTheComponentsOfTheSoloProject();

        $this->patchWorkStations(['components' => $this->annex->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(422);
        $this->patchWorkStations(['components' => null]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($stored, $this->storedWorkStations());
    }

    public function testLockedTypeOfAnotherTypeDoesNotBlockTheChoiceOfAnUnlockedType(): void
    {
        $this->lockTheComponentsOfTheSoloProject();

        $this->patchWorkStations(['final_products' => $this->workshop->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['final_products'], array_keys($this->storedWorkStations()));
    }

    public function testStructureOfAnotherUserIsRefusedAndNothingIsStored(): void
    {
        $foreign = (new IndustryStructureConfig())
            ->setUser($this->member)
            ->setName('Foreign Raitaru')
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFacilityTaxPercent(0.0)
            ->setRigs([]);
        $this->em->persist($foreign);
        $this->em->flush();

        $this->patchWorkStations(['components' => $foreign->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testStructureThatDoesNotExistIsRefused(): void
    {
        $this->patchWorkStations(['components' => Uuid::v7()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testValueThatIsNotAStructureIdIsRefused(): void
    {
        $this->patchWorkStations(['components' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testValueOfTheWrongTypeIsRefused(): void
    {
        $this->patchWorkStations(['components' => 5]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testStructureThatCannotRunTheActivityOfTheTypeIsRefused(): void
    {
        $refinery = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName('Jita Athanor')
            ->setStructureType('athanor')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFacilityTaxPercent(0.0)
            ->setRigs([]);
        $this->em->persist($refinery);
        $this->em->flush();

        $this->patchWorkStations(['components' => $refinery->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testTypeAbsentFromThePlanIsRefused(): void
    {
        foreach (['reaction', 'hulls', 'blueprints'] as $absentType) {
            $this->patchWorkStations([$absentType => $this->workshop->getId()->toRfc4122()]);

            self::assertResponseStatusCodeSame(422, $absentType);
        }
        self::assertSame([], $this->storedWorkStations());
    }

    public function testUnknownTypeOfWorkIsRefused(): void
    {
        $this->patchWorkStations(['paint_shop' => $this->workshop->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testMoreEntriesThanTypesOfWorkAreRefused(): void
    {
        $workshopId = $this->workshop->getId()->toRfc4122();

        $this->patchWorkStations([
            'reaction' => $workshopId,
            'components' => $workshopId,
            'hulls' => $workshopId,
            'final_products' => $workshopId,
            'blueprints' => $workshopId,
            'paint_shop' => $workshopId,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testRefusedEntryLeavesTheValidEntriesOfTheSameRequestUnstored(): void
    {
        $this->patchWorkStations(['components' => $this->workshop->getId()->toRfc4122(), 'paint_shop' => $this->workshop->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testEmptyMapIsWithoutEffect(): void
    {
        $this->patchWorkStations([]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->storedWorkStations());
    }

    public function testMemberWhoIsNeitherOwnerNorAdminCannotChooseTheStations(): void
    {
        $this->ishtarBatch->setStatus(ProjectStatus::Draft);
        $this->em->flush();

        $this->actAs($this->member);
        $this->request('PATCH', $this->projectUri($this->ishtarBatch), ['workStations' => ['final_products' => $this->workshop->getId()->toRfc4122()]]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame([], $this->em->find(IndustryProject::class, $this->ishtarBatch->getId())->getWorkStations());
    }

    /**
     * @param array<string, string|int|null> $workStations
     */
    private function patchWorkStations(array $workStations, bool $reseedingThePrices = false): void
    {
        $this->actAs($this->owner);
        if ($reseedingThePrices) {
            $this->seedAdjustedPrices([
                self::RAM_STARSHIP_TECH_TYPE_ID => self::RAM_ADJUSTED_PRICE,
                self::TRITANIUM_TYPE_ID => self::TRITANIUM_ADJUSTED_PRICE,
            ]);
            $this->seedJitaCostIndex('manufacturing', self::JITA_MANUFACTURING_COST_INDEX);
            $this->seedJitaSellOrderBooks([
                self::RAM_STARSHIP_TECH_TYPE_ID => [['price' => self::RAM_SELL_PRICE, 'volume' => 100_000]],
                self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_SELL_PRICE, 'volume' => 1_000_000]],
            ]);
        }
        $this->request('PATCH', $this->projectUri($this->soloIshtar), ['workStations' => [] === $workStations ? new \stdClass() : $workStations]);
    }

    private function request(string $method, string $uri, array|\stdClass|null $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'CONTENT_TYPE' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function workStationsUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/work-stations';
    }

    /**
     * @return array<string, mixed>
     */
    private function workTypeOf(string $workType): array
    {
        $found = array_values(array_filter($this->responseJson()['workTypes'], static fn (array $entry): bool => $entry['workType'] === $workType));
        self::assertCount(1, $found, \sprintf('Expected one entry for the type of work %s.', $workType));

        return $found[0];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function legOfKind(array $row, string $kind): array
    {
        $found = array_values(array_filter($row['transportLegs'], static fn (array $leg): bool => $leg['kind'] === $kind));
        self::assertCount(1, $found, \sprintf('Expected one %s leg.', $kind));

        return $found[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedWorkStations(): array
    {
        $this->em->clear();

        return $this->em->find(IndustryProject::class, $this->soloIshtar->getId())->getWorkStations();
    }

    private function bomLineOf(int $typeId): IndustryProjectBomLine
    {
        $project = $this->em->find(IndustryProject::class, $this->soloIshtar->getId());
        $lines = array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $line): bool => $line->getTypeId() === $typeId && $line->isJob()));
        self::assertCount(1, $lines, \sprintf('Expected one job line of the type %d.', $typeId));

        return $lines[0];
    }

    private function lockTheComponentsOfTheSoloProject(): void
    {
        $project = $this->em->find(IndustryProject::class, $this->soloIshtar->getId());
        $lines = array_values(array_filter($project->getBomItems()->toArray(), static fn (IndustryProjectBomLine $line): bool => $line->getTypeId() === self::RAM_STARSHIP_TECH_TYPE_ID && $line->isJob()));
        self::assertCount(1, $lines);
        $lines[0]->addJobMatch((new IndustryProjectJobMatch())->setEsiJobId(self::ISHTAR_JOB_ID)->setRuns(self::RAM_RUNS)->setStatus('active')->setManual(true));
        $this->em->flush();
    }

    private function purchaseOf(int $typeId, int $quantity, float $unitPrice): IndustryProjectPurchase
    {
        return (new IndustryProjectPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
    }

    private function configureTheStructuresOfTheOwnerInJita(): void
    {
        $this->workshop = $this->raitaruInJita(self::WORKSHOP_NAME, self::WORKSHOP_FACILITY_TAX_PERCENT);
        $this->annex = $this->raitaruInJita(self::ANNEX_NAME, self::ANNEX_FACILITY_TAX_PERCENT);
        $this->em->persist($this->workshop);
        $this->em->persist($this->annex);
        $this->em->persist((new IndustryUserSettings())
            ->setUser($this->owner)
            ->setFavoriteManufacturingSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setExportCostPerM3(self::COURIER_ISK_PER_CUBIC_METER)
            ->setJumpBridgeCostPerM3(self::JUMP_BRIDGE_ISK_PER_CUBIC_METER));
        $this->em->flush();
    }

    private function legacyStructureOfTheOwner(string $name, string $structureType): IndustryStructureConfig
    {
        $structure = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName($name)
            ->setStructureType($structureType)
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFacilityTaxPercent(0.0)
            ->setRigs([]);
        $this->em->persist($structure);
        $this->em->flush();

        return $structure;
    }

    private function raitaruInJita(string $name, float $facilityTaxPercent): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName($name)
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFacilityTaxPercent($facilityTaxPercent)
            ->setRigs([]);
    }

    private function seedIshtarBlueprintsAndTheProjectProduct(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SYNTHETIC_CATEGORY_ID)->setCategoryName('Ship');
        $group = (new InvGroup())->setGroupId(self::SYNTHETIC_GROUP_ID)->setGroupName('Heavy Assault Cruiser')->setCategory($category);
        $this->em->persist($category);
        $this->em->persist($group);
        foreach ([
            self::ISHTAR_TYPE_ID => ['Ishtar', self::ISHTAR_CUBIC_METERS],
            self::RAM_STARSHIP_TECH_TYPE_ID => ['R.A.M.- Starship Tech', self::RAM_CUBIC_METERS],
            self::TRITANIUM_TYPE_ID => ['Tritanium', self::TRITANIUM_CUBIC_METERS],
        ] as $typeId => [$typeName, $cubicMeters]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setVolume($cubicMeters)->setGroup($group));
        }
        $this->ishtarBatch->addItem((new IndustryProjectProduct())
            ->setTypeId(self::ISHTAR_TYPE_ID)
            ->setTypeName('Ishtar')
            ->setMeLevel(4)
            ->setTeLevel(8)
            ->setRuns(self::ISHTAR_RUNS)
            ->setSortOrder(0));
        $this->em->flush();

        $connection = self::getContainer()->get(Connection::class);
        $blueprints = [
            self::ISHTAR_BLUEPRINT_TYPE_ID => [self::ISHTAR_TYPE_ID, 1, [self::RAM_STARSHIP_TECH_TYPE_ID => self::RAM_BASE_QUANTITY_PER_ISHTAR_RUN, self::TRITANIUM_TYPE_ID => self::ISHTAR_TRITANIUM_PER_RUN]],
            self::RAM_BLUEPRINT_TYPE_ID => [self::RAM_STARSHIP_TECH_TYPE_ID, self::RAM_UNITS_PER_BLUEPRINT_RUN, [self::TRITANIUM_TYPE_ID => self::RAM_TRITANIUM_PER_RUN]],
        ];
        foreach ($blueprints as $blueprintTypeId => [$productTypeId, $unitsPerRun, $materials]) {
            $connection->insert('sde_industry_blueprints', ['type_id' => $blueprintTypeId, 'max_production_limit' => 200]);
            $connection->insert('sde_industry_activities', ['type_id' => $blueprintTypeId, 'activity_id' => self::ACTIVITY_MANUFACTURING, 'time' => 900]);
            $connection->insert('sde_industry_activity_products', [
                'type_id' => $blueprintTypeId,
                'activity_id' => self::ACTIVITY_MANUFACTURING,
                'product_type_id' => $productTypeId,
                'quantity' => $unitsPerRun,
            ]);
            foreach ($materials as $materialTypeId => $baseQuantityPerRun) {
                $connection->insert('sde_industry_activity_materials', [
                    'type_id' => $blueprintTypeId,
                    'activity_id' => self::ACTIVITY_MANUFACTURING,
                    'material_type_id' => $materialTypeId,
                    'quantity' => $baseQuantityPerRun,
                ]);
            }
        }
    }
}
