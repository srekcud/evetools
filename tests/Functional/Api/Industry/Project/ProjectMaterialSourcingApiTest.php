<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\MapSolarSystemJump;
use App\Constant\EveConstants;
use App\Tests\Functional\Api\Industry\SeedsJitaOrderBooksAndCostIndices;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-466')]
final class ProjectMaterialSourcingApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 999_466_001;
    private const int RAM_BLUEPRINT_TYPE_ID = 999_466_002;
    private const int SYNTHETIC_CATEGORY_ID = 999_466_003;
    private const int SYNTHETIC_GROUP_ID = 999_466_004;
    private const int REGION_ID = 999_466_005;
    private const int CONSTELLATION_ID = 999_466_006;
    private const int URLEN_SYSTEM_ID = 999_466_007;
    private const int WORKSHOP_SYSTEM_ID = 999_466_008;
    private const int STRUCTURE_ID = 1_035_466_543_993;
    private const string STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower';
    private const string STRUCTURE_SYNCED_AT = '2026-11-08T10:00:00+00:00';

    private const float TRITANIUM_VOLUME = 0.01;
    private const float MORPHITE_VOLUME = 0.1;
    private const float TRITANIUM_JITA_PRICE = 5.0;
    private const float MORPHITE_JITA_PRICE = 18_000.0;
    private const float TRITANIUM_STRUCTURE_PRICE = 10.0;
    private const float MORPHITE_STRUCTURE_PRICE = 17_000.0;
    private const int WORKSHOP_JUMPS = 2;
    private const float IMPORT_ISK_PER_CUBIC_METER_JUMP = 150.0;
    private const int MORPHITE_REMAINING = 147;
    private const int NOCXIUM_REMAINING = 600;
    private const int TRITANIUM_REQUIRED = 12_000;

    private const array ROW_KEYS = [
        'bestTotal', 'importTotal', 'jitaTotal', 'jitaWithImportTotal', 'jumps', 'missingData', 'quantity', 'saving',
        'side', 'structureFallback', 'structureTotal', 'typeId', 'typeName', 'volumePerUnit', 'warnings',
    ];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->seedIshtarBlueprintsAndTheProjectProduct();
        $this->seedVolumesRouteAndWorkshopSystem();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $this->seedJitaSellOrderBooks([
            self::TRITANIUM_TYPE_ID => [['price' => self::TRITANIUM_JITA_PRICE, 'volume' => 10_000_000]],
            self::MORPHITE_TYPE_ID => [['price' => self::MORPHITE_JITA_PRICE, 'volume' => 1_000]],
        ]);
        $this->seedNoJitaSellOrderFor(self::NOCXIUM_TYPE_ID);
    }

    public function testAcceptedParticipantReadsTheRemainingMaterialsComparedBetweenJitaWithImportAndHisPreferredStructure(): void
    {
        $this->preferTheStructureAndSyncItsMarket();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->sourcingUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame(
            [$this->ishtarBatch->getId()->toRfc4122(), self::IMPORT_ISK_PER_CUBIC_METER_JUMP, self::STRUCTURE_ID, self::STRUCTURE_NAME, true, self::STRUCTURE_SYNCED_AT, 3],
            [$body['id'], $body['importIskPerCubicMeterJump'], $body['structureId'], $body['structureName'], $body['structureSynced'], $body['structureLastSync'], \count($body['rows'])],
        );
        [$tritanium, $nocxium, $morphite] = $this->rowsSortedBy('typeId', $body['rows']);
        $keys = array_keys($tritanium);
        sort($keys);
        self::assertSame(self::ROW_KEYS, $keys);
        self::assertEqualsWithDelta(
            [self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_REQUIRED, 'jita', false, 60_000.0, 36_000.0, 96_000.0, 120_000.0, 96_000.0, 24_000.0, self::WORKSHOP_JUMPS],
            $this->sourcingFieldsOf($tritanium),
            0.01,
        );
        self::assertEqualsWithDelta(
            [self::MORPHITE_TYPE_ID, 'Morphite', self::MORPHITE_REMAINING, 'structure', false, 2_646_000.0, 4_410.0, 2_650_410.0, 2_499_000.0, 2_499_000.0, 151_410.0, self::WORKSHOP_JUMPS],
            $this->sourcingFieldsOf($morphite),
            0.01,
        );
        self::assertSame(
            [self::NOCXIUM_TYPE_ID, self::NOCXIUM_REMAINING, 'unknown', null, null, null, null, null],
            [$nocxium['typeId'], $nocxium['quantity'], $nocxium['side'], $nocxium['jitaTotal'], $nocxium['jitaWithImportTotal'], $nocxium['structureTotal'], $nocxium['bestTotal'], $nocxium['saving']],
        );
        self::assertSame(['jitaWithImport' => null, 'structure' => null, 'best' => null], $body['totals']);
    }

    public function testMaterialAlreadyFulfilledIsNotToSource(): void
    {
        $this->tritaniumLine->setFulfilledQuantity(self::TRITANIUM_REQUIRED);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->sourcingUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([self::NOCXIUM_TYPE_ID, self::MORPHITE_TYPE_ID], array_column($this->rowsSortedBy('typeId', $this->responseJson()['rows']), 'typeId'));
    }

    public function testWithoutPreferredStructureEveryMaterialGoesToJitaAndTheStructureIsAbsent(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->sourcingUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([null, null, false, null], [$body['structureId'], $body['structureName'], $body['structureSynced'], $body['structureLastSync']]);
        [$tritanium, , $morphite] = $this->rowsSortedBy('typeId', $body['rows']);
        self::assertSame(['jita', false, null], [$morphite['side'], $morphite['structureFallback'], $morphite['structureTotal']]);
        self::assertSame(['jita', false, null], [$tritanium['side'], $tritanium['structureFallback'], $tritanium['structureTotal']]);
    }

    public function testPreferredStructureWhoseMarketIsNotSyncedFallsBackToJitaAndSaysSo(): void
    {
        $this->member->setPreferredMarketStructureId(self::STRUCTURE_ID)->setPreferredMarketStructureName(self::STRUCTURE_NAME);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->sourcingUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([self::STRUCTURE_ID, self::STRUCTURE_NAME, false, null], [$body['structureId'], $body['structureName'], $body['structureSynced'], $body['structureLastSync']]);
        [$tritanium] = $this->rowsSortedBy('typeId', $body['rows']);
        self::assertSame(['jita', true], [$tritanium['side'], $tritanium['structureFallback']]);
    }

    #[Group('issue-880')]
    public function testFirstMarketStructureOfTheListIsTheSourcingStructureWhenNoStructureIsPreferred(): void
    {
        $this->member->setPreferredMarketStructureId(null)->setPreferredMarketStructureName(null);
        $this->member->setMarketStructures([
            ['id' => self::STRUCTURE_ID, 'name' => self::STRUCTURE_NAME],
            ['id' => self::STRUCTURE_ID + 1, 'name' => 'Ashab - Market Fortizar'],
        ]);
        $this->em->flush();

        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->sourcingUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        self::assertSame([self::STRUCTURE_ID, self::STRUCTURE_NAME, false, null], [$body['structureId'], $body['structureName'], $body['structureSynced'], $body['structureLastSync']]);
        [$tritanium] = $this->rowsSortedBy('typeId', $body['rows']);
        self::assertSame(['jita', true], [$tritanium['side'], $tritanium['structureFallback']]);
    }

    public function testPilotOfAnotherCorporationIsRefusedTheSourcing(): void
    {
        $this->client->loginUser($this->outsider, 'api');
        $this->request('GET', $this->sourcingUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testSourcingOfAnUnknownProjectIsNotFound(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->sourcingUri(Uuid::v7()->toRfc4122()));

        self::assertResponseStatusCodeSame(404);
        self::assertSame([404, 'Project not found'], $this->errorDetail());
    }

    private function sourcingUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/material-sourcing';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    private function sourcingFieldsOf(array $row): array
    {
        return [
            $row['typeId'], $row['typeName'], $row['quantity'], $row['side'], $row['structureFallback'],
            $row['jitaTotal'], $row['importTotal'], $row['jitaWithImportTotal'], $row['structureTotal'], $row['bestTotal'], $row['saving'], $row['jumps'],
        ];
    }

    private function preferTheStructureAndSyncItsMarket(): void
    {
        $this->member->setPreferredMarketStructureId(self::STRUCTURE_ID)->setPreferredMarketStructureName(self::STRUCTURE_NAME);
        $this->em->flush();
        $this->saveInCache('structure_market.cache', 'structure_market_'.self::STRUCTURE_ID, [
            self::TRITANIUM_TYPE_ID => ['sell' => self::TRITANIUM_STRUCTURE_PRICE, 'buy' => null],
            self::MORPHITE_TYPE_ID => ['sell' => self::MORPHITE_STRUCTURE_PRICE, 'buy' => null],
        ]);
        $this->saveInCache('structure_market.cache', 'structure_market_'.self::STRUCTURE_ID.'_meta', ['syncedAt' => new \DateTimeImmutable(self::STRUCTURE_SYNCED_AT)]);
    }

    private function seedVolumesRouteAndWorkshopSystem(): void
    {
        $group = $this->em->find(InvGroup::class, self::SYNTHETIC_GROUP_ID);
        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        $this->em->persist($region);
        $this->em->persist($constellation);
        $this->em->persist((new InvType())->setTypeId(self::MORPHITE_TYPE_ID)->setTypeName('Morphite')->setGroup($group)->setVolume(self::MORPHITE_VOLUME));
        $tritanium = $this->em->find(InvType::class, self::TRITANIUM_TYPE_ID);
        $tritanium->setVolume(self::TRITANIUM_VOLUME);
        foreach ([EveConstants::JITA_SOLAR_SYSTEM_ID => 'Jita', self::URLEN_SYSTEM_ID => 'Urlen', self::WORKSHOP_SYSTEM_ID => 'Perimeter'] as $solarSystemId => $name) {
            $this->em->persist((new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name)->setConstellation($constellation)->setRegionId(self::REGION_ID));
        }
        foreach ([[EveConstants::JITA_SOLAR_SYSTEM_ID, self::URLEN_SYSTEM_ID], [self::URLEN_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID]] as [$from, $to]) {
            $this->em->persist((new MapSolarSystemJump())
                ->setFromSolarSystemId($from)
                ->setToSolarSystemId($to)
                ->setFromRegionId(self::REGION_ID)
                ->setFromConstellationId(self::CONSTELLATION_ID)
                ->setToRegionId(self::REGION_ID)
                ->setToConstellationId(self::CONSTELLATION_ID));
        }
        $this->em->persist((new IndustryUserSettings())->setUser($this->owner)->setFavoriteManufacturingSystemId(self::WORKSHOP_SYSTEM_ID));
        $this->em->flush();
    }

    private function seedIshtarBlueprintsAndTheProjectProduct(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SYNTHETIC_CATEGORY_ID)->setCategoryName('Ship');
        $group = (new InvGroup())->setGroupId(self::SYNTHETIC_GROUP_ID)->setGroupName('Heavy Assault Cruiser')->setCategory($category);
        $this->em->persist($category);
        $this->em->persist($group);
        foreach ([
            self::ISHTAR_TYPE_ID => 'Ishtar',
            self::RAM_STARSHIP_TECH_TYPE_ID => 'R.A.M.- Starship Tech',
            self::TRITANIUM_TYPE_ID => 'Tritanium',
        ] as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group));
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
            self::ISHTAR_BLUEPRINT_TYPE_ID => [self::ISHTAR_TYPE_ID, 1, [self::RAM_STARSHIP_TECH_TYPE_ID => 155, self::TRITANIUM_TYPE_ID => 5_000]],
            self::RAM_BLUEPRINT_TYPE_ID => [self::RAM_STARSHIP_TECH_TYPE_ID, self::RAM_UNITS_PER_RUN, [self::TRITANIUM_TYPE_ID => 400]],
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
