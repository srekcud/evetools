<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\Character;
use App\Entity\CharacterStructureAccess;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\SourcingMarket;
use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1300')]
#[Group('issue-1336')]
final class PlacedActionsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const string PLACE_ACTIONS_URI = '/api/industry/place-actions';
    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e52';
    private const int AZBEL_LOCATION_ID = 1_040_000_001_000;
    private const int MARKET_STRUCTURE_ID = 1_035_466_617_946;
    private const string MARKET_STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower';
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const int REGION_ID = 10_000_002;
    private const int CONSTELLATION_ID = 20_000_020;

    private IndustryStructureConfig $azbel;
    private array $locationsByEveCharacterId = [];

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->owner->setPreferredMarketStructureId(self::MARKET_STRUCTURE_ID)->setPreferredMarketStructureName(self::MARKET_STRUCTURE_NAME);
        $region = (new MapRegion())->setRegionId(self::REGION_ID)->setRegionName('The Forge');
        $constellation = (new MapConstellation())->setConstellationId(self::CONSTELLATION_ID)->setConstellationName('Kimotoro')->setRegion($region);
        $perimeter = (new MapSolarSystem())->setSolarSystemId(self::PERIMETER_SYSTEM_ID)->setSolarSystemName('Perimeter')->setConstellation($constellation)->setRegionId(self::REGION_ID);
        $this->azbel = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName('Azbel')
            ->setLocationId(self::AZBEL_LOCATION_ID)
            ->setSolarSystemId(self::PERIMETER_SYSTEM_ID)
            ->setStructureType('azbel')
            ->setSecurityType('highsec')
            ->setRigs([]);
        foreach ([$region, $constellation, $perimeter, $this->azbel] as $row) {
            $this->em->persist($row);
        }
        $this->tritaniumLine->setSourcingMarket(SourcingMarket::Structure);
        $this->hecateTritaniumLine->setSourcingMarket(SourcingMarket::Structure);
        $this->ramJobLine->setPlannedStructureId($this->azbel->getId()->toRfc4122());
        $this->ishtarJobLine->setPlannedStructureId($this->azbel->getId()->toRfc4122());
        $this->em->flush();
        $this->replaceLocationReader();
    }

    public function testPlacedActionsAreRefusedWithoutConnectedUser(): void
    {
        foreach ([self::PLACE_ACTIONS_URI, $this->projectUri($this->ishtarBatch).'/place-actions'] as $uri) {
            $this->request('GET', $uri);

            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testOwnerReadsTheActionsOfAllItsProjectsWithTheDockedPlaceFirst(): void
    {
        $this->locationsByEveCharacterId[$this->owner->getMainCharacter()?->getEveCharacterId()] = new CharacterLocation(self::PERIMETER_SYSTEM_ID, null, self::AZBEL_LOCATION_ID, true, null);
        $this->actAs($this->owner);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        $actions = $this->responseJson();
        self::assertSame(
            [
                ['launch_job', 'Ishtar batch', 'Ishtar', 2, true],
                ['launch_job', 'Ishtar batch', 'R.A.M.- Starship Tech', 3, true],
                ['source_material', 'Hecate batch', 'Tritanium', 40_000, false],
                ['source_material', 'Ishtar batch', 'Tritanium', 12_000, false],
                ['source_material', 'Ishtar batch', 'Morphite', 147, null],
                ['source_material', 'Ishtar batch', 'Nocxium', 600, null],
                ['review_contributions', 'Ishtar batch', null, 1, null],
                ['review_join_requests', 'Ishtar batch', null, 1, null],
            ],
            array_map(static fn (array $action): array => [$action['code'], $action['projectName'], $action['typeName'], $action['quantity'], $action['here']], $actions),
        );
        foreach ($actions as $action) {
            self::assertSame(['access', 'bomLineId', 'code', 'here', 'id', 'place', 'projectId', 'projectName', 'quantity', 'reservedByOthers', 'typeId', 'typeName'], $this->sortedKeys($action));
            self::assertArrayHasKey('here', $action);
        }
        self::assertSame(
            [
                'id' => $this->ishtarBatch->getId()->toRfc4122().':launch_job:'.$this->idOf($this->ishtarJobLine),
                'projectId' => $this->ishtarBatch->getId()->toRfc4122(),
                'projectName' => 'Ishtar batch',
                'code' => 'launch_job',
                'bomLineId' => $this->idOf($this->ishtarJobLine),
                'typeId' => self::ISHTAR_TYPE_ID,
                'typeName' => 'Ishtar',
                'quantity' => self::ISHTAR_RUNS,
                'place' => ['kind' => 'station', 'locationId' => self::AZBEL_LOCATION_ID, 'name' => 'Azbel', 'solarSystemId' => self::PERIMETER_SYSTEM_ID, 'solarSystemName' => 'Perimeter', 'unknownReason' => null],
                'here' => true,
                'access' => 'unknown',
                'reservedByOthers' => [],
            ],
            $actions[0],
        );
        self::assertSame(['unknown', 'unknown', null, null, null, null, null, null], array_column($actions, 'access'));
        self::assertSame(
            ['kind' => 'station', 'locationId' => self::MARKET_STRUCTURE_ID, 'name' => self::MARKET_STRUCTURE_NAME, 'solarSystemId' => null, 'solarSystemName' => null, 'unknownReason' => null],
            $actions[2]['place'],
        );
        self::assertSame(
            ['kind' => 'unknown', 'locationId' => null, 'name' => null, 'solarSystemId' => null, 'solarSystemName' => null, 'unknownReason' => 'market_not_chosen'],
            $actions[4]['place'],
        );
        self::assertSame(
            ['kind' => 'anywhere', 'locationId' => null, 'name' => null, 'solarSystemId' => null, 'solarSystemName' => null, 'unknownReason' => null],
            $actions[7]['place'],
        );
        self::assertArrayHasKey('bomLineId', $actions[7]);
        self::assertNull($actions[7]['bomLineId']);
        self::assertArrayHasKey('typeId', $actions[7]);
        self::assertNull($actions[7]['typeId']);
        self::assertSame($this->ishtarBatch->getId()->toRfc4122().':review_join_requests:-', $actions[7]['id']);
    }

    public function testMemberDeniedInTheStationOfAStepIsNotOfferedItWhileAGrantedOwnerIsOfferedItFlaggedGranted(): void
    {
        $this->holdAccess($this->member, self::AZBEL_LOCATION_ID, StructureAccessStatus::Denied);
        $this->holdAccess($this->owner, self::AZBEL_LOCATION_ID, StructureAccessStatus::Granted);

        $this->actAs($this->member);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [['source_material', 'Morphite'], ['source_material', 'Nocxium'], ['source_material', 'Tritanium']],
            array_map(static fn (array $action): array => [$action['code'], $action['typeName']], $this->responseJson()),
        );

        $this->actAs($this->owner);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        $actions = $this->responseJson();
        $launches = $this->rowsSortedBy('typeName', array_values(array_filter($actions, static fn (array $action): bool => 'launch_job' === $action['code'])));
        self::assertSame([['Ishtar', 'granted'], ['R.A.M.- Starship Tech', 'granted']], array_map(static fn (array $action): array => [$action['typeName'], $action['access']], $launches));
        $sourcing = array_values(array_filter($actions, static fn (array $action): bool => 'source_material' === $action['code']))[0];
        self::assertArrayHasKey('access', $sourcing);
        self::assertNull($sourcing['access']);
    }

    public function testMemberWithoutAnyProofIsOfferedTheStepFlaggedUnknown(): void
    {
        $this->actAs($this->member);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        $launches = $this->rowsSortedBy('typeName', array_values(array_filter($this->responseJson(), static fn (array $action): bool => 'launch_job' === $action['code'])));
        self::assertCount(2, $launches);
        foreach ($launches as $launch) {
            self::assertArrayHasKey('access', $launch);
            self::assertSame('unknown', $launch['access']);
        }
    }

    public function testProjectRouteReadsTheActionsOfThatProjectOnly(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->hecateBatch).'/place-actions');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [[$this->hecateBatch->getId()->toRfc4122(), 'source_material', 'Tritanium', 40_000]],
            array_map(static fn (array $action): array => [$action['projectId'], $action['code'], $action['typeName'], $action['quantity']], $this->responseJson()),
        );

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/place-actions');

        self::assertResponseStatusCodeSame(200);
        $actions = $this->responseJson();
        self::assertCount(7, $actions);
        self::assertSame([$this->ishtarBatch->getId()->toRfc4122()], array_values(array_unique(array_column($actions, 'projectId'))));
    }

    public function testMemberWithoutManagementRightsReadsNoReviewActionAndHisOwnMarketPlaces(): void
    {
        $this->actAs($this->member);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        $actions = $this->responseJson();
        self::assertSame(
            [
                ['launch_job', 'Ishtar', 'station', null],
                ['launch_job', 'R.A.M.- Starship Tech', 'station', null],
                ['source_material', 'Morphite', 'unknown', 'market_not_chosen'],
                ['source_material', 'Nocxium', 'unknown', 'market_not_chosen'],
                ['source_material', 'Tritanium', 'unknown', 'market_structure_unset'],
            ],
            array_map(static fn (array $action): array => [$action['code'], $action['typeName'], $action['place']['kind'], $action['place']['unknownReason']], $actions),
        );
        foreach ($actions as $action) {
            self::assertArrayHasKey('here', $action);
            self::assertNull($action['here']);
        }
    }

    public function testPilotOutsideTheProjectIsRefusedItsActionsAndReadsNoneInTheCrossProjectList(): void
    {
        foreach ([$this->outsider, $this->pendingMember] as $pilot) {
            $this->actAs($pilot);
            $this->request('GET', $this->projectUri($this->ishtarBatch).'/place-actions');

            self::assertResponseStatusCodeSame(403);
            self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
        }

        $this->actAs($this->outsider);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->responseJson());
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFound(): void
    {
        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/place-actions');

            self::assertResponseStatusCodeSame(404);
            self::assertSame([404, 'Project not found'], $this->errorDetail());
        }
    }

    public function testReservationOfAnotherMemberReducesThePurchaseAndHidesTheLaunchFromThePilotWhoDidNotReserve(): void
    {
        $this->em->persist(new IndustryProjectLineReservation($this->tritaniumLine, $this->memberMembership, 5_000));
        $this->em->persist(new IndustryProjectLineReservation($this->ramJobLine, $this->memberMembership, self::RAM_RUNS));
        $this->em->flush();

        $this->actAs($this->owner);
        $this->request('GET', $this->projectUri($this->ishtarBatch).'/place-actions');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                'launch_job:Ishtar' => [self::ISHTAR_RUNS, []],
                'source_material:Morphite' => [147, []],
                'source_material:Nocxium' => [600, []],
                'source_material:Tritanium' => [7_000, [['characterName' => 'Brann Holt', 'quantity' => 5_000]]],
            ],
            $this->quantitiesWithReservedByOthers($this->responseJson()),
        );

        $this->actAs($this->member);
        $this->request('GET', self::PLACE_ACTIONS_URI);

        self::assertResponseStatusCodeSame(200);
        $memberActions = $this->quantitiesWithReservedByOthers($this->responseJson());
        self::assertSame([self::RAM_RUNS, []], $memberActions['launch_job:R.A.M.- Starship Tech']);
        self::assertSame([12_000, []], $memberActions['source_material:Tritanium']);
    }

    private function replaceLocationReader(): void
    {
        $reader = $this->createStub(CharacterLocationReader::class);
        $reader->method('read')->willReturnCallback(
            fn (Character $character): ?CharacterLocation => $this->locationsByEveCharacterId[$character->getEveCharacterId()] ?? null,
        );
        self::getContainer()->set(CharacterLocationReader::class, $reader);
    }

    private function holdAccess(User $user, int $structureId, StructureAccessStatus $status): void
    {
        $this->em->persist(new CharacterStructureAccess($user->getMainCharacter(), $structureId, $status, StructureAccessSource::Esi, new \DateTimeImmutable()));
        $this->em->flush();
    }

    private function quantitiesWithReservedByOthers(array $actions): array
    {
        $rows = [];
        foreach ($actions as $action) {
            if (null === $action['typeName'] || 'Hecate batch' === $action['projectName']) {
                continue;
            }
            self::assertArrayHasKey('reservedByOthers', $action);
            $rows[$action['code'].':'.$action['typeName']] = [$action['quantity'], $action['reservedByOthers']];
        }
        ksort($rows);

        return $rows;
    }

    private function sortedKeys(array $action): array
    {
        $keys = array_keys($action);
        sort($keys);

        return $keys;
    }
}
