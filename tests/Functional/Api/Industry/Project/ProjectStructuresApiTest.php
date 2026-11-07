<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-341')]
final class ProjectStructuresApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 999_341_001;
    private const int RAM_BLUEPRINT_TYPE_ID = 999_341_002;
    private const int SYNTHETIC_CATEGORY_ID = 999_341_003;
    private const int SYNTHETIC_GROUP_ID = 999_341_004;
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
    private const float MINIMUM_OUTSIDE_SAVING = 125_000.5;
    private const string WORKSHOP_NAME = 'Jita Raitaru';
    private const float CENTS = 0.01;

    private const array RESOURCE_KEYS = ['acceptsOutsideFavoriteSystem', 'groups', 'id', 'minimumOutsideSaving', 'transportIskPerCubicMeterJump'];

    private const array GROUP_KEYS = [
        'activity', 'cheapestStructureId', 'choiceStatus', 'chosenStructureId', 'jobs', 'rows', 'runs', 'savingIfCheapest', 'typeId', 'typeName',
    ];

    private const array ROW_KEYS = [
        'delta', 'facilityTaxPercent', 'inFavoriteSystem', 'installationCost', 'jumps', 'kind', 'materialBonusPercent', 'materialsCost',
        'missingData', 'name', 'solarSystemId', 'solarSystemName', 'structureId', 'totalCost', 'transportCost', 'transportedCubicMeters',
    ];

    private IndustryStructureConfig $workshop;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->configureTheWorkshopOfTheOwnerInJita();
        $this->seedAdjustedPrices([
            self::RAM_STARSHIP_TECH_TYPE_ID => self::RAM_ADJUSTED_PRICE,
            self::TRITANIUM_TYPE_ID => self::TRITANIUM_ADJUSTED_PRICE,
        ]);
        $this->seedJitaCostIndex('manufacturing', self::JITA_MANUFACTURING_COST_INDEX);
    }

    public function testAcceptedParticipantReadsTheProjectFlagsTheTransportRateAndOneGroupPerProductOfThePlanInItsOrder(): void
    {
        $this->seedJitaSellOrders();
        $this->ishtarBatch->setAcceptsOutsideFavoriteSystem(true)->setMinimumOutsideSaving(self::MINIMUM_OUTSIDE_SAVING);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->structuresUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(self::RESOURCE_KEYS, $keys);
        self::assertSame(
            [$this->ishtarBatch->getId()->toRfc4122(), true, self::MINIMUM_OUTSIDE_SAVING, 150.0, [self::ISHTAR_TYPE_ID, self::RAM_STARSHIP_TECH_TYPE_ID]],
            [$body['id'], $body['acceptsOutsideFavoriteSystem'], $body['minimumOutsideSaving'], $body['transportIskPerCubicMeterJump'], array_column($body['groups'], 'typeId')],
        );
        $groupKeys = array_keys($body['groups'][0] ?? []);
        sort($groupKeys);
        self::assertSame(self::GROUP_KEYS, $groupKeys);
        $rowKeys = array_keys($body['groups'][0]['rows'][0] ?? []);
        sort($rowKeys);
        self::assertSame(self::ROW_KEYS, $rowKeys);
    }

    public function testGroupOfTheIshtarComparesTheStructureOfTheOwnerRetainedByThePlanWithItsCostBreakdown(): void
    {
        $this->seedJitaSellOrders();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->structuresUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $groups = $this->responseJson()['groups'];
        self::assertNotSame([], $groups);
        $group = $groups[0];
        $workshopId = $this->workshop->getId()->toRfc4122();
        self::assertSame(
            [
                self::ISHTAR_TYPE_ID, 'Ishtar', 'manufacturing', 1, self::ISHTAR_RUNS,
                $workshopId, 'best_in_favorite_system', $workshopId, null,
            ],
            $this->fieldsOf($group, ['typeId', 'typeName', 'activity', 'jobs', 'runs', 'chosenStructureId', 'choiceStatus', 'cheapestStructureId', 'savingIfCheapest']),
        );
        self::assertCount(1, $group['rows']);
        $row = $group['rows'][0];
        self::assertSame(
            [$workshopId, self::WORKSHOP_NAME, 'raitaru', EveConstants::JITA_SOLAR_SYSTEM_ID, true, 0, 0.0, self::WORKSHOP_FACILITY_TAX_PERCENT, [], 391_900.0, 0.0],
            $this->fieldsOf($row, ['structureId', 'name', 'kind', 'solarSystemId', 'inFavoriteSystem', 'jumps', 'materialBonusPercent', 'facilityTaxPercent', 'missingData', 'totalCost', 'delta']),
        );
        self::assertSame([360_000.0, 31_900.0, 0.0], $this->fieldsOf($row, ['materialsCost', 'installationCost', 'transportCost']));
        self::assertEqualsWithDelta(100_112.4, $row['transportedCubicMeters'], self::CENTS);
    }

    public function testUnknownMarketPricesGiveNullMaterialsTotalAndGapNeverZeroWhileInstallationAndTransportStayKnown(): void
    {
        $this->seedNoJitaSellOrderFor(self::RAM_STARSHIP_TECH_TYPE_ID, self::TRITANIUM_TYPE_ID);

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->structuresUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $groups = $this->responseJson()['groups'];
        self::assertNotSame([], $groups);
        $row = $groups[0]['rows'][0];
        self::assertSame(
            [null, 31_900.0, 0.0, null, null],
            $this->fieldsOf($row, ['materialsCost', 'installationCost', 'transportCost', 'totalCost', 'delta']),
        );
        $missingTypeIds = array_column($row['missingData'], 'typeId');
        sort($missingTypeIds);
        self::assertSame(
            [[self::TRITANIUM_TYPE_ID, self::RAM_STARSHIP_TECH_TYPE_ID], ['market_price']],
            [$missingTypeIds, array_values(array_unique(array_column($row['missingData'], 'reason')))],
        );
    }

    public function testPilotOfAnotherCorporationIsRefusedTheStructures(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->structuresUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testStructuresOfAnUnknownProjectAreNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->structuresUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function structuresUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/structures';
    }

    private function seedJitaSellOrders(): void
    {
        $this->seedJitaSellOrderBooks([
            self::RAM_STARSHIP_TECH_TYPE_ID => [['price' => self::RAM_SELL_PRICE, 'volume' => 100_000]],
            self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_SELL_PRICE, 'volume' => 1_000_000]],
        ]);
    }

    private function configureTheWorkshopOfTheOwnerInJita(): void
    {
        $this->workshop = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName(self::WORKSHOP_NAME)
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFacilityTaxPercent(self::WORKSHOP_FACILITY_TAX_PERCENT)
            ->setRigs([]);
        $this->em->persist($this->workshop);
        $this->em->persist((new IndustryUserSettings())->setUser($this->owner)->setFavoriteManufacturingSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID));
        $this->em->flush();
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
