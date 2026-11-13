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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-837')]
final class AssetsServiceItemNamesCostTest extends TestCase
{
    use StubsSdeTypeClassification;

    private const int CORPORATION_ID = 98000001;
    private const string ASSETS_ENDPOINT = '/corporations/98000001/assets/';
    private const string NAMES_ENDPOINT = '/corporations/98000001/assets/names/';

    private const int JITA_STATION_ID = 60003760;
    private const int TYPE_STATION_CONTAINER = 17366;
    private const int FIRST_ITEM_ID = 1000000001;
    private const int NAMES_CHUNK_SIZE = 1000;
    private const int MAX_CALLS_FOR_A_FULLY_REJECTED_CHUNK = 100;

    /** @var list<list<int>> */
    private array $namesRequestBodies = [];

    private int $rejectionStatusCode = 404;

    protected function setUp(): void
    {
        $this->namesRequestBodies = [];
        $this->rejectionStatusCode = 404;
    }

    public function testAChunkWhoseIdsAreAllRejectedWithA404CostsABoundedNumberOfCalls(): void
    {
        $this->rejectionStatusCode = 404;
        $assetsService = $this->createAssetsService($this->containers(self::NAMES_CHUNK_SIZE));

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        self::assertLessThanOrEqual(self::MAX_CALLS_FOR_A_FULLY_REJECTED_CHUNK, \count($this->namesRequestBodies));
        self::assertGreaterThanOrEqual(1, $assetsService->lastNameBatchFailures());
        self::assertNull($assets[self::FIRST_ITEM_ID]->itemName);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function limitedStatusCodes(): array
    {
        return ['error limited' => [420], 'rate limited' => [429]];
    }

    #[DataProvider('limitedStatusCodes')]
    public function testALimitedResponseStopsTheBisectionOfTheChunk(int $statusCode): void
    {
        $this->rejectionStatusCode = $statusCode;
        $assetsService = $this->createAssetsService($this->containers(8));

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertCount(1, $this->namesRequestBodies);
        self::assertSame(1, $assetsService->lastNameBatchFailures());
    }

    #[DataProvider('limitedStatusCodes')]
    public function testALimitedResponseAbandonsTheRemainingChunksAndReportsThemAsFailures(int $statusCode): void
    {
        $this->rejectionStatusCode = $statusCode;
        $assetsService = $this->createAssetsService($this->containers(self::NAMES_CHUNK_SIZE * 2 + 500));

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertCount(1, $this->namesRequestBodies);
        self::assertSame(3, $assetsService->lastNameBatchFailures());
    }

    public function testAnErrorOtherThanA404IsNotBisected(): void
    {
        $this->rejectionStatusCode = 502;
        $assetsService = $this->createAssetsService($this->containers(8));

        $assetsService->getCorporationAssets($this->createCharacter());

        self::assertCount(1, $this->namesRequestBodies);
        self::assertSame(1, $assetsService->lastNameBatchFailures());
    }

    public function testA404OnASingleUnnameableIdIsStillBisectedSoTheOthersKeepTheirNames(): void
    {
        $this->rejectionStatusCode = 404;
        $assetsService = $this->createAssetsService($this->containers(8), [self::FIRST_ITEM_ID]);

        $assets = $this->indexByItemId($assetsService->getCorporationAssets($this->createCharacter()));

        self::assertNull($assets[self::FIRST_ITEM_ID]->itemName);
        self::assertSame('Name of ' . (self::FIRST_ITEM_ID + 7), $assets[self::FIRST_ITEM_ID + 7]->itemName);
        self::assertSame(1, $assetsService->lastNameBatchFailures());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function containers(int $count): array
    {
        $rawAssets = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $rawAssets[] = [
                'item_id' => self::FIRST_ITEM_ID + $offset,
                'type_id' => self::TYPE_STATION_CONTAINER,
                'quantity' => 1,
                'location_id' => self::JITA_STATION_ID,
                'location_type' => 'station',
                'location_flag' => 'CorpSAG1',
                'is_singleton' => true,
            ];
        }

        return $rawAssets;
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     * @param list<int>|null             $onlyRejectedItemIds
     */
    private function createAssetsService(array $rawAssets, ?array $onlyRejectedItemIds = null): AssetsService
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturnCallback(
            static fn (string $endpoint): array => $endpoint === self::ASSETS_ENDPOINT ? $rawAssets : [],
        );
        $esiClient->method('get')->willReturn(['name' => 'Jita IV', 'system_id' => 30000142]);
        $esiClient->method('post')->willReturnCallback(function (string $endpoint, array $body) use ($onlyRejectedItemIds): array {
            if ($endpoint !== self::NAMES_ENDPOINT) {
                return [];
            }

            $this->namesRequestBodies[] = $body;

            $rejected = $onlyRejectedItemIds === null || array_intersect($body, $onlyRejectedItemIds) !== [];
            if ($rejected) {
                throw new EsiApiException('rejected', $this->rejectionStatusCode, $endpoint);
            }

            return array_map(
                static fn (int $itemId): array => ['item_id' => $itemId, 'name' => 'Name of ' . $itemId],
                $body,
            );
        });

        return new AssetsService(
            $esiClient,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
            $this->createStub(StaStationRepository::class),
            $this->createSdeClassification([self::TYPE_STATION_CONTAINER => self::cargoContainerClassification()]),
        );
    }

    private function createCharacter(): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $character;
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
