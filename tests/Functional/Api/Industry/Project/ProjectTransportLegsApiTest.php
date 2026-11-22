<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Dto\Transfer\TransferEndpoint;
use App\Dto\Transfer\TransferLine;
use App\Entity\CharacterStructureAccess;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStationTransfer;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Enum\TransferCarrierKind;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

#[Group('issue-1364')]
final class ProjectTransportLegsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int AMARR_SYSTEM_ID = 30_002_187;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;
    private const int CATEGORY_ID = 999_364_001;
    private const int GROUP_ID = 999_364_002;
    private const int STATION_A_LOCATION_ID = 1_042_000_000_011;
    private const int STATION_B_LOCATION_ID = 1_042_000_000_022;
    private const int TRITANIUM_QUANTITY_ON_THE_ROAD = 500;
    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e64';

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
        $this->persistAccessOfMember(self::STATION_A_LOCATION_ID);
        $this->persistAccessOfMember(self::STATION_B_LOCATION_ID);
        $this->persistTritaniumOnTheRoadFromAToB();
        $this->em->flush();
    }

    public function testAcceptedMemberReadsTheHaulLegsWithNullsKeptInTheResponse(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->transportLegsUri());

        self::assertResponseStatusCodeSame(200);
        $response = $this->responseJson();
        self::assertSame(['id', 'legs', 'personalStockUnknownReason', 'unreadable'], $this->sortedKeys($response));
        self::assertSame($this->ishtarBatch->getId()->toRfc4122(), $response['id']);
        self::assertSame('personal_assets_not_synced', $response['personalStockUnknownReason']);
        self::assertSame(
            [['typeId' => self::TRITANIUM_TYPE_ID, 'typeName' => 'Tritanium', 'locationId' => self::STATION_B_LOCATION_ID, 'reason' => 'container_location_unknown']],
            $response['unreadable'],
        );

        self::assertCount(1, $response['legs']);
        $leg = $response['legs'][0];
        self::assertSame(['access', 'arrival', 'carrierCharacterIds', 'departure', 'key', 'lines', 'volume'], $this->sortedKeys($leg));
        self::assertSame('1042000000011>1042000000022', $leg['key']);
        self::assertSame(
            ['locationId' => self::STATION_A_LOCATION_ID, 'stationName' => 'Structure A', 'solarSystemId' => self::PERIMETER_SYSTEM_ID, 'solarSystemName' => 'Perimeter'],
            $leg['departure'],
        );
        self::assertSame(
            ['locationId' => self::STATION_B_LOCATION_ID, 'stationName' => 'Structure B', 'solarSystemId' => self::AMARR_SYSTEM_ID, 'solarSystemName' => 'Amarr'],
            $leg['arrival'],
        );
        self::assertSame('granted', $leg['access']);
        self::assertSame([$this->member->getMainCharacter()->getEveCharacterId()], $leg['carrierCharacterIds']);
        self::assertSame(5.0, $leg['volume']);

        self::assertCount(1, $leg['lines']);
        $line = $leg['lines'][0];
        self::assertSame(['quantity', 'readyAt', 'source', 'state', 'typeId', 'typeName', 'unitVolume', 'unknownReason', 'volume'], $this->sortedKeys($line));
        self::assertSame(
            [self::TRITANIUM_TYPE_ID, 'Tritanium', self::TRITANIUM_QUANTITY_ON_THE_ROAD, 'in_transit', 'transfer', 0.01, 5.0],
            [$line['typeId'], $line['typeName'], $line['quantity'], $line['state'], $line['source'], $line['unitVolume'], $line['volume']],
        );
        self::assertArrayHasKey('readyAt', $line);
        self::assertNull($line['readyAt']);
        self::assertArrayHasKey('unknownReason', $line);
        self::assertNull($line['unknownReason']);
    }

    public function testPilotOutsideTheProjectIsRefusedTheHaulLegs(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->transportLegsUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFound(): void
    {
        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/transport-legs');

            self::assertResponseStatusCodeSame(404);
            self::assertSame([404, 'Project not found'], $this->errorDetail());
        }
    }

    public function testHaulLegsAreRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', $this->transportLegsUri());

        self::assertResponseStatusCodeSame(401);
    }

    private function transportLegsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/transport-legs';
    }

    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }

    private function persistAccessOfMember(int $structureId): void
    {
        $this->em->persist(new CharacterStructureAccess($this->member->getMainCharacter(), $structureId, StructureAccessStatus::Granted, StructureAccessSource::Esi, new \DateTimeImmutable()));
    }

    private function persistTritaniumOnTheRoadFromAToB(): void
    {
        $this->em->persist(new IndustryProjectStationTransfer(
            $this->em->getReference(IndustryProject::class, $this->ishtarBatch->getId()),
            $this->em->getReference(User::class, $this->member->getId()),
            Uuid::v7(),
            0,
            TransferCarrierKind::Courier,
            new TransferEndpoint(self::PERIMETER_SYSTEM_ID, self::STATION_A_LOCATION_ID),
            new TransferEndpoint(self::AMARR_SYSTEM_ID, self::STATION_B_LOCATION_ID),
            [new TransferLine(self::TRITANIUM_TYPE_ID, self::TRITANIUM_QUANTITY_ON_THE_ROAD, null, null)],
            new \DateTimeImmutable('2026-11-21T10:00:00+00:00'),
        ));
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
        foreach ([self::PERIMETER_SYSTEM_ID => 'Perimeter', self::AMARR_SYSTEM_ID => 'Amarr'] as $solarSystemId => $name) {
            $this->em->persist((new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name)->setConstellation($constellation)->setRegionId(self::REGION_ID));
        }
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
}
