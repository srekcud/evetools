<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Support\StubsSdeTypeClassification;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-779')]
final class AssetsServiceCorporationItemNamesTest extends TestCase
{
    use StubsSdeTypeClassification;

    private const int CORPORATION_ID = 98000001;
    private const string ASSETS_ENDPOINT = '/corporations/98000001/assets/';
    private const string NAMES_ENDPOINT = '/corporations/98000001/assets/names/';

    private const int JITA_STATION_ID = 60003760;
    private const int TYPE_OFFICE = 27;
    private const int TYPE_STATION_CONTAINER = 17366;

    private const int OFFICE_ITEM_ID = 1000000100;
    private const int FIRST_CONTAINER_ITEM_ID = 1000000001;
    private const int SECOND_CONTAINER_ITEM_ID = 1000000002;
    private const int THIRD_CONTAINER_ITEM_ID = 1000000003;
    private const int UNNAMEABLE_ITEM_ID = 1000000999;

    /** @var list<list<int>> */
    private array $namesRequestBodies = [];

    /** @var list<int> */
    private array $unnameableItemIds = [];

    protected function setUp(): void
    {
        $this->namesRequestBodies = [];
        $this->unnameableItemIds = [];
    }

    public function testOfficesAreNotSentToTheCorporationNamesEndpointAndTheirContainersKeepTheirNames(): void
    {
        $this->unnameableItemIds = [self::OFFICE_ITEM_ID];
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::OFFICE_ITEM_ID, self::TYPE_OFFICE, 'OfficeFolder'),
            $this->rawAsset(self::FIRST_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG1'),
            $this->rawAsset(self::SECOND_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG2'),
        ]);

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        self::assertSame([[self::FIRST_CONTAINER_ITEM_ID, self::SECOND_CONTAINER_ITEM_ID]], $this->namesRequestBodies);
        self::assertSame('Name of ' . self::FIRST_CONTAINER_ITEM_ID, $assets[self::FIRST_CONTAINER_ITEM_ID]->itemName);
        self::assertSame('Name of ' . self::SECOND_CONTAINER_ITEM_ID, $assets[self::SECOND_CONTAINER_ITEM_ID]->itemName);
        self::assertSame(0, $assetsService->lastNameBatchFailures());
    }

    public function testRejectedNamesBatchIsSplitSoTheValidIdsKeepTheirNames(): void
    {
        $this->unnameableItemIds = [self::UNNAMEABLE_ITEM_ID];
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::FIRST_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG1'),
            $this->rawAsset(self::UNNAMEABLE_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG1'),
            $this->rawAsset(self::SECOND_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG2'),
            $this->rawAsset(self::THIRD_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG3'),
        ]);

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        self::assertSame('Name of ' . self::FIRST_CONTAINER_ITEM_ID, $assets[self::FIRST_CONTAINER_ITEM_ID]->itemName);
        self::assertSame('Name of ' . self::SECOND_CONTAINER_ITEM_ID, $assets[self::SECOND_CONTAINER_ITEM_ID]->itemName);
        self::assertSame('Name of ' . self::THIRD_CONTAINER_ITEM_ID, $assets[self::THIRD_CONTAINER_ITEM_ID]->itemName);
        self::assertNull($assets[self::UNNAMEABLE_ITEM_ID]->itemName);
        self::assertSame(1, $assetsService->lastNameBatchFailures());
    }

    public function testAcceptedNamesBatchIsSentOnceWithoutSplitting(): void
    {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::FIRST_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG1'),
            $this->rawAsset(self::SECOND_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 'CorpSAG2'),
        ]);

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertCount(1, $this->namesRequestBodies);
        self::assertSame(0, $assetsService->lastNameBatchFailures());
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createAssetsService(array $rawAssets): AssetsService
    {
        return new AssetsService(
            $this->createEsiClient($rawAssets),
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
            $this->createStub(StaStationRepository::class),
            $this->createSdeClassification([self::TYPE_STATION_CONTAINER => self::cargoContainerClassification()]),
        );
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createEsiClient(array $rawAssets): EsiClient
    {
        $esiClient = $this->createStub(EsiClient::class);

        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::ASSETS_ENDPOINT ? $rawAssets : [],
        );

        $esiClient->method('get')->willReturn(['name' => 'Jita IV', 'system_id' => 30000142]);

        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body): array {
            if ($endpoint !== self::NAMES_ENDPOINT) {
                return [];
            }

            $this->namesRequestBodies[] = $body;

            if (array_intersect($body, $this->unnameableItemIds) !== []) {
                throw new EsiApiException('Invalid IDs in the request', 404);
            }

            return array_map(
                static fn (int $itemId): array => ['item_id' => $itemId, 'name' => 'Name of ' . $itemId],
                $body,
            );
        });

        return $esiClient;
    }

    private function createCharacter(): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $character;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAsset(int $itemId, int $typeId, string $locationFlag): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'quantity' => 1,
            'location_id' => self::JITA_STATION_ID,
            'location_type' => 'station',
            'location_flag' => $locationFlag,
            'is_singleton' => true,
        ];
    }

    /**
     * @param AssetDto[] $assets
     * @return array<int, AssetDto>
     */
    private function indexByItemId(array $assets): array
    {
        $indexed = [];
        foreach ($assets as $asset) {
            $indexed[$asset->itemId] = $asset;
        }

        return $indexed;
    }
}
