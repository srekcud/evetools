<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Constant\EveConstants;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-342')]
final class ProjectStructureHealthApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 999_342_001;
    private const int RAM_BLUEPRINT_TYPE_ID = 999_342_002;
    private const int SYNTHETIC_CATEGORY_ID = 999_342_003;
    private const int SYNTHETIC_GROUP_ID = 999_342_004;
    private const int RAM_BASE_QUANTITY_PER_ISHTAR_RUN = 155;
    private const int RAM_UNITS_PER_BLUEPRINT_RUN = 100;
    private const int RAM_TRITANIUM_PER_RUN = 400;
    private const int ISHTAR_TRITANIUM_PER_RUN = 5_000;
    private const float ISHTAR_CUBIC_METERS = 50_000.0;
    private const float RAM_CUBIC_METERS = 0.04;
    private const float TRITANIUM_CUBIC_METERS = 0.01;
    private const int WORKSHOP_STRUCTURE_ID = 1_035_342_000_001;
    private const string WORKSHOP_NAME = 'Jita Raitaru';
    private const float LOW_FUEL_THRESHOLD_DAYS = 3.0;

    private const array RESOURCE_KEYS = ['id', 'lowFuelThresholdDays', 'rows'];
    private const array ROW_KEYS = ['fuelDays', 'kind', 'name', 'offlineServices', 'state', 'structureId'];

    private IndustryStructureConfig $workshop;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->configureTheWorkshopOfTheOwner();
    }

    public function testPlannedStructureWhoseCorporationCannotBeReadIsUnknownAndNeverOk(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->healthUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        $keys = array_keys($body);
        sort($keys);
        self::assertSame(self::RESOURCE_KEYS, $keys);
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122(), self::LOW_FUEL_THRESHOLD_DAYS], [$body['id'], $body['lowFuelThresholdDays']]);
        self::assertCount(1, $body['rows']);
        $rowKeys = array_keys($body['rows'][0]);
        sort($rowKeys);
        self::assertSame(self::ROW_KEYS, $rowKeys);
        self::assertSame(
            [$this->workshop->getId()->toRfc4122(), self::WORKSHOP_NAME, 'raitaru', 'unknown', null, []],
            array_map(static fn (string $key): mixed => $body['rows'][0][$key], ['structureId', 'name', 'kind', 'state', 'fuelDays', 'offlineServices']),
        );
    }

    public function testNoCorporationStructuresAreReadWhenNoCharacterHoldsTheStationManagerRole(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->healthUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], array_values(array_filter($this->esiRequests, static fn (string $request): bool => str_contains($request, '/structures/'))));
    }

    public function testPilotOfAnotherCorporationIsRefusedTheStructureHealth(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->healthUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testStructureHealthOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->healthUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function healthUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/structure-health';
    }

    private function configureTheWorkshopOfTheOwner(): void
    {
        $this->workshop = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName(self::WORKSHOP_NAME)
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setLocationId(self::WORKSHOP_STRUCTURE_ID)
            ->setCorporationId(self::OWNER_CORPORATION_ID)
            ->setFacilityTaxPercent(1.0)
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
