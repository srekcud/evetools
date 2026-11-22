<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryStructureConfig;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-708')]
final class ProjectIntermediateStockProposalApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int HYDROGEN_FUEL_BLOCK_TYPE_ID = 4_246;
    private const int HYDROGEN_FUEL_BLOCK_REQUIRED = 100;
    private const int HYDROGEN_FUEL_BLOCK_IN_HANGAR = 30;
    private const int HYDROGEN_FUEL_BLOCK_AT_ISHTAR = 12;
    private const int JITA_LOCATION_ID = 60_003_760;
    private const int ISHTAR_LOCATION_ID = 1_035_466_617_946;
    private const string JITA_LOCATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string ISHTAR_LOCATION_NAME = 'Ishtar Citadel';

    private IndustryProjectBomLine $hydrogenFuelBlockLine;

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->hydrogenFuelBlockLine = $this->addJobLine($this->hecateBatch, self::HYDROGEN_FUEL_BLOCK_TYPE_ID, 'Hydrogen Fuel Block', 'component', 10, self::HYDROGEN_FUEL_BLOCK_REQUIRED, 10, 20, self::HECATE_TYPE_ID);
        $asset = (new CachedAsset())
            ->setItemId(1_070_800_000_001)
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK_TYPE_ID)
            ->setTypeName('Hydrogen Fuel Block')
            ->setQuantity(self::HYDROGEN_FUEL_BLOCK_IN_HANGAR)
            ->setLocationId(self::JITA_LOCATION_ID)
            ->setLocationName(self::JITA_LOCATION_NAME)
            ->setSolarSystemName('Jita')
            ->setLocationType('station')
            ->setCharacter($this->owner->getMainCharacter())
            ->setIsCorporationAsset(false);
        $this->em->persist($asset);
        $this->em->flush();
    }

    public function testOwnerOfASoloProjectReadsTheStockFoundInItsPersonalAssets(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->proposalUri($this->hecateBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('unknownReason', $resource);
        self::assertNull($resource['unknownReason']);
        self::assertSame(
            ['id' => $this->hecateBatch->getId()->toRfc4122(), 'state' => 'ready'],
            ['id' => $resource['id'], 'state' => $resource['state']],
        );
        self::assertCount(1, $resource['lines']);
        $line = $resource['lines'][0];
        self::assertArrayHasKey('containerQuantity', $line);
        self::assertNull($line['containerQuantity']);
        unset($line['containerQuantity']);
        self::assertArrayHasKey('plannedStationName', $line);
        self::assertNull($line['plannedStationName']);
        unset($line['plannedStationName']);
        self::assertCount(1, $line['stockByStation']);
        $stockByStation = [$this->withoutNullOnSite($line['stockByStation'][0])];
        unset($line['stockByStation']);
        self::assertSame(
            [[
                'locationId' => self::JITA_LOCATION_ID,
                'locationName' => self::JITA_LOCATION_NAME,
                'quantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'systemName' => 'Jita',
            ]],
            array_map($this->sortedKeys(...), $stockByStation),
        );
        self::assertSame(
            [
                'bomItemId' => $this->idOf($this->hydrogenFuelBlockLine),
                'demandQuantity' => self::HYDROGEN_FUEL_BLOCK_REQUIRED,
                'personalQuantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'proposedQuantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'remainingQuantity' => self::HYDROGEN_FUEL_BLOCK_REQUIRED,
                'stockQuantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                'typeId' => self::HYDROGEN_FUEL_BLOCK_TYPE_ID,
                'typeName' => 'Hydrogen Fuel Block',
            ],
            $this->sortedKeys($line),
        );
    }

    public function testStationOfThePlanComesFirstAndIsOnSiteWhileTheOtherStationIsOffSite(): void
    {
        $this->plannedAtIshtarWithStockThere();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->proposalUri($this->hecateBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertCount(1, $resource['lines']);
        $line = $resource['lines'][0];
        self::assertArrayHasKey('plannedStationName', $line);
        self::assertSame('Ishtar', $line['plannedStationName']);
        self::assertSame(
            [
                [
                    'locationId' => self::ISHTAR_LOCATION_ID,
                    'locationName' => self::ISHTAR_LOCATION_NAME,
                    'onSite' => true,
                    'quantity' => self::HYDROGEN_FUEL_BLOCK_AT_ISHTAR,
                    'systemName' => 'Gamis',
                ],
                [
                    'locationId' => self::JITA_LOCATION_ID,
                    'locationName' => self::JITA_LOCATION_NAME,
                    'onSite' => false,
                    'quantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                    'systemName' => 'Jita',
                ],
            ],
            array_map($this->sortedKeys(...), $line['stockByStation']),
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK_IN_HANGAR + self::HYDROGEN_FUEL_BLOCK_AT_ISHTAR, self::HYDROGEN_FUEL_BLOCK_IN_HANGAR + self::HYDROGEN_FUEL_BLOCK_AT_ISHTAR],
            [$line['personalQuantity'], $line['proposedQuantity']],
        );
    }

    public function testLineWithoutPlannedStructureKeepsTheReaderOrderWithoutOnSiteFlag(): void
    {
        $this->stockAtIshtar();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->proposalUri($this->hecateBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $line = $this->responseJson()['lines'][0];
        self::assertArrayHasKey('plannedStationName', $line);
        self::assertNull($line['plannedStationName']);
        self::assertCount(2, $line['stockByStation']);
        self::assertSame(
            [
                [
                    'locationId' => self::JITA_LOCATION_ID,
                    'locationName' => self::JITA_LOCATION_NAME,
                    'quantity' => self::HYDROGEN_FUEL_BLOCK_IN_HANGAR,
                    'systemName' => 'Jita',
                ],
                [
                    'locationId' => self::ISHTAR_LOCATION_ID,
                    'locationName' => self::ISHTAR_LOCATION_NAME,
                    'quantity' => self::HYDROGEN_FUEL_BLOCK_AT_ISHTAR,
                    'systemName' => 'Gamis',
                ],
            ],
            array_map(
                fn (array $entry): array => $this->sortedKeys($this->withoutNullOnSite($entry)),
                $line['stockByStation'],
            ),
        );
    }

    public function testAcceptedMemberWhoDoesNotOwnTheProjectIsRefused(): void
    {
        $this->client->loginUser($this->member, 'api');
        $this->request('GET', $this->proposalUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupProjectIsNotSoloForItsOwner(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->proposalUri($this->ishtarBatch->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $resource = $this->responseJson();
        self::assertArrayHasKey('unknownReason', $resource);
        self::assertNull($resource['unknownReason']);
        self::assertSame(['not_solo', []], [$resource['state'], $resource['lines']]);
    }

    private function plannedAtIshtarWithStockThere(): void
    {
        $ishtar = (new IndustryStructureConfig())
            ->setUser($this->owner)
            ->setName('Ishtar')
            ->setStructureType('raitaru')
            ->setSecurityType('highsec')
            ->setLocationId(self::ISHTAR_LOCATION_ID)
            ->setSolarSystemId(30_002_187)
            ->setRigs([]);
        $this->em->persist($ishtar);
        $this->hydrogenFuelBlockLine->setPlannedStructureId($ishtar->getId()->toRfc4122());
        $this->stockAtIshtar();
    }

    private function stockAtIshtar(): void
    {
        $asset = (new CachedAsset())
            ->setItemId(1_070_800_000_002)
            ->setTypeId(self::HYDROGEN_FUEL_BLOCK_TYPE_ID)
            ->setTypeName('Hydrogen Fuel Block')
            ->setQuantity(self::HYDROGEN_FUEL_BLOCK_AT_ISHTAR)
            ->setLocationId(self::ISHTAR_LOCATION_ID)
            ->setLocationName(self::ISHTAR_LOCATION_NAME)
            ->setSolarSystemName('Gamis')
            ->setLocationType('station')
            ->setCharacter($this->owner->getMainCharacter())
            ->setIsCorporationAsset(false);
        $this->em->persist($asset);
        $this->em->flush();
    }

    private function withoutNullOnSite(array $entry): array
    {
        self::assertArrayHasKey('onSite', $entry);
        self::assertNull($entry['onSite']);
        unset($entry['onSite']);

        return $entry;
    }

    private function proposalUri(string $projectId): string
    {
        return '/api/industry/projects/'.$projectId.'/intermediate-stock-proposal';
    }

    private function sortedKeys(array $payload): array
    {
        ksort($payload);

        return $payload;
    }
}
