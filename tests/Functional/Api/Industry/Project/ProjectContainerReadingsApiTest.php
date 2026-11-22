<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\EveToken;
use App\Service\ESI\EsiClient;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-1293')]
final class ProjectContainerReadingsApiTest extends WebTestCase
{
    use BuildsIshtarGroupProject;

    private const int STATION_A = 100;
    private const int STATION_B = 200;
    private const int OFFICE_ITEM_ID = 1_129_300_000_001;
    private const int ISHTAR_A_ITEM_ID = 1_129_300_000_002;
    private const int PYERITE_TYPE_ID = 35;
    private const int STATION_CONTAINER_TYPE_ID = 17366;
    private const int OFFICE_TYPE_ID = 27;
    private const string UNKNOWN_PROJECT_ID = '0192f5c2-7c3e-7d4a-9b1e-3f6a2c8d4e51';

    protected function setUp(): void
    {
        $this->setUpIshtarGroupProject();
        $this->ishtarBatch->addContainerAt('Ishtar A', self::STATION_A);
        $this->ishtarBatch->addContainerAt('Ishtar B', self::STATION_B);
        $this->connectDirectorOfTheOwnerCorporation();
        $this->em->persist((new CorpAssetVisibility())->setCorporationId(self::OWNER_CORPORATION_ID)->setVisibleDivisions([2])->setConfiguredBy($this->owner));
        $this->persistCorporationAsset(self::OFFICE_ITEM_ID, self::OFFICE_TYPE_ID, 1, self::STATION_A, 'station', EveConstants::OFFICE_LOCATION_FLAG, null);
        $this->persistCorporationAsset(self::ISHTAR_A_ITEM_ID, self::STATION_CONTAINER_TYPE_ID, 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Ishtar A');
        $this->persistCorporationAsset(1_129_300_000_003, self::TRITANIUM_TYPE_ID, 50, self::ISHTAR_A_ITEM_ID, 'item', 'Unlocked', null, 'Tritanium');
        $this->persistCorporationAsset(1_129_300_000_004, self::PYERITE_TYPE_ID, 10, self::ISHTAR_A_ITEM_ID, 'item', 'Unlocked', null, 'Pyerite');
        $this->em->flush();
    }

    public function testAcceptedMemberReadsOneReadingPerContainerKnownOrUnknown(): void
    {
        $this->actAs($this->member);
        $this->request('GET', $this->containerReadingsUri());

        self::assertResponseStatusCodeSame(200);
        $readings = $this->responseJson();
        self::assertSame(
            [
                ['Ishtar A', self::STATION_A, 'known', null],
                ['Ishtar B', self::STATION_B, 'unknown', 'container_not_found'],
                [self::CONTAINER_NAME, null, 'unknown', 'container_not_found'],
            ],
            array_map(static fn (array $reading): array => [$reading['containerName'], $reading['locationId'], $reading['status'], $reading['unknownReason']], $readings),
        );
        self::assertSame(
            [[self::TRITANIUM_TYPE_ID, 'Tritanium', 50], [self::PYERITE_TYPE_ID, 'Pyerite', 10]],
            array_map(static fn (array $item): array => [$item['typeId'], $item['typeName'], $item['quantity']], $this->itemsSortedByQuantityDescending($readings[0]['items'])),
        );
    }

    public function testUnknownReadingSerializesNullItemsAndKnownReadingSerializesNullReason(): void
    {
        $this->actAs($this->owner);
        $this->request('GET', $this->containerReadingsUri());

        self::assertResponseStatusCodeSame(200);
        $readings = $this->responseJson();
        self::assertCount(3, $readings);
        foreach ($readings as $reading) {
            self::assertSame(['assetsSyncedAt', 'containerId', 'containerName', 'items', 'locationId', 'status', 'unknownReason'], $this->sortedKeys($reading));
            self::assertNotNull($reading['assetsSyncedAt']);
        }
        self::assertArrayHasKey('unknownReason', $readings[0]);
        self::assertNull($readings[0]['unknownReason']);
        self::assertIsArray($readings[0]['items']);
        self::assertArrayHasKey('items', $readings[1]);
        self::assertNull($readings[1]['items']);
        self::assertArrayHasKey('locationId', $readings[2]);
        self::assertNull($readings[2]['locationId']);
    }

    public function testPilotOutsideTheProjectIsRefusedTheReadings(): void
    {
        $this->actAs($this->outsider);
        $this->request('GET', $this->containerReadingsUri());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([403, 'You do not have access to this project'], $this->errorDetail());
    }

    public function testProjectIdentifierThatIsNotAUuidOrMatchesNoProjectIsNotFound(): void
    {
        foreach ([self::NOT_A_UUID, self::UNKNOWN_PROJECT_ID] as $projectId) {
            $this->actAs($this->owner);
            $this->request('GET', '/api/industry/projects/'.$projectId.'/container-readings');

            self::assertResponseStatusCodeSame(404);
            self::assertSame([404, 'Project not found'], $this->errorDetail());
        }
    }

    public function testReadingsAreRefusedWithoutConnectedUser(): void
    {
        $this->request('GET', $this->containerReadingsUri());

        self::assertResponseStatusCodeSame(401);
    }

    private function containerReadingsUri(): string
    {
        return $this->projectUri($this->ishtarBatch).'/container-readings';
    }

    private function itemsSortedByQuantityDescending(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => $right['quantity'] <=> $left['quantity']);

        return $items;
    }

    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }

    private function connectDirectorOfTheOwnerCorporation(): void
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
    }

    private function persistCorporationAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, ?string $locationFlag, ?string $itemName, ?string $typeName = null): void
    {
        $this->em->persist(
            (new CachedAsset())
                ->setItemId($itemId)
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setQuantity($quantity)
                ->setLocationId($locationId)
                ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
                ->setLocationType($locationType)
                ->setLocationFlag($locationFlag)
                ->setItemName($itemName)
                ->setCorporationId(self::OWNER_CORPORATION_ID)
                ->setIsCorporationAsset(true),
        );
    }
}
