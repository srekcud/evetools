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
use App\Service\ESI\AssetsService;
use App\Service\ESI\EsiClient;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-670')]
final class AssetsServiceItemNamesTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2112000001;
    private const string ASSETS_ENDPOINT = '/characters/2112000001/assets/';
    private const string NAMES_ENDPOINT = '/characters/2112000001/assets/names/';
    private const string UNIVERSE_NAMES_ENDPOINT = '/universe/names/';

    private const int JITA_STATION_ID = 60003760;
    private const int TYPE_TRITANIUM = 34;
    private const int TYPE_STATION_CONTAINER = 17366;
    private const int NAMES_CHUNK_SIZE = 1000;

    private const int CONTAINER_ITEM_ID = 1000000001;
    private const int SECOND_CONTAINER_ITEM_ID = 1000000002;
    private const int TRITANIUM_ITEM_ID = 2000000001;
    private const string CONTAINER_NAME = 'Project Alpha';
    private const string ESI_ERROR_MESSAGE = 'Bad Request: too many ids';

    /** @var list<list<int>> */
    private array $namesRequestBodies = [];

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $loggedRecords = [];

    private int $namesCallsToFail = 0;

    protected function setUp(): void
    {
        $this->namesRequestBodies = [];
        $this->loggedRecords = [];
        $this->namesCallsToFail = 0;
    }

    public function testOnlySingletonItemsAreSentToTheNamesEndpoint(): void
    {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, true),
            $this->rawAsset(self::TRITANIUM_ITEM_ID, self::TYPE_TRITANIUM, 5000, false),
            $this->rawAsset(self::SECOND_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, true),
        ]);

        $assetsService->getCharacterAssets($this->createCharacter());

        self::assertSame([[self::CONTAINER_ITEM_ID, self::SECOND_CONTAINER_ITEM_ID]], $this->namesRequestBodies);
    }

    public function testNamesEndpointIsNotCalledWhenNoItemIsASingleton(): void
    {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::TRITANIUM_ITEM_ID, self::TYPE_TRITANIUM, 5000, false),
        ]);

        $assetsService->getCharacterAssets($this->createCharacter());

        self::assertSame([], $this->namesRequestBodies);
    }

    public function testCustomNameOfASingletonIsKept(): void
    {
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, true),
            $this->rawAsset(self::TRITANIUM_ITEM_ID, self::TYPE_TRITANIUM, 5000, false),
        ]);

        $assets = $this->indexByItemId($assetsService->getCharacterAssets($this->createCharacter()));

        self::assertSame(self::CONTAINER_NAME, $assets[self::CONTAINER_ITEM_ID]->itemName);
        self::assertNull($assets[self::TRITANIUM_ITEM_ID]->itemName);
    }

    public function testRejectedNamesBatchIsLoggedAsAWarningWithTheEsiError(): void
    {
        $this->namesCallsToFail = 1;
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, true),
        ]);

        $assetsService->getCharacterAssets($this->createCharacter());

        $warnings = array_values(array_filter(
            $this->loggedRecords,
            static fn (array $record): bool => $record['level'] === 'warning'
                && ($record['context']['error'] ?? null) === self::ESI_ERROR_MESSAGE,
        ));
        self::assertCount(1, $warnings);
        self::assertSame(self::NAMES_ENDPOINT, $warnings[0]['context']['endpoint']);
        self::assertSame(1, $warnings[0]['context']['batchSize']);
    }

    public function testRejectedNamesBatchIsCountedAndTheNextBatchKeepsItsNames(): void
    {
        $this->namesCallsToFail = 1;
        $rawAssets = [];
        for ($offset = 0; $offset < self::NAMES_CHUNK_SIZE; ++$offset) {
            $rawAssets[] = $this->rawAsset(3000000000 + $offset, self::TYPE_STATION_CONTAINER, 1, true);
        }
        $rawAssets[] = $this->rawAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, true);
        $assetsService = $this->createAssetsService($rawAssets);

        $assets = $this->indexByItemId($assetsService->getCharacterAssets($this->createCharacter()));

        self::assertSame(1, $assetsService->lastNameBatchFailures());
        self::assertNull($assets[3000000000]->itemName);
        self::assertSame(self::CONTAINER_NAME, $assets[self::CONTAINER_ITEM_ID]->itemName);
    }

    public function testFailureCountIsResetByTheNextFetch(): void
    {
        $this->namesCallsToFail = 1;
        $assetsService = $this->createAssetsService([
            $this->rawAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1, true),
        ]);

        $assetsService->getCharacterAssets($this->createCharacter());
        self::assertSame(1, $assetsService->lastNameBatchFailures());

        $assetsService->getCharacterAssets($this->createCharacter());
        self::assertSame(0, $assetsService->lastNameBatchFailures());
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function createAssetsService(array $rawAssets): AssetsService
    {
        return new AssetsService(
            $this->createEsiClient($rawAssets),
            $this->createSolarSystemRepository(),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createRecordingLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
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

            if ($this->namesCallsToFail > 0) {
                --$this->namesCallsToFail;
                throw new EsiApiException(self::ESI_ERROR_MESSAGE, 400);
            }

            return array_map(
                static fn (int $itemId): array => [
                    'item_id' => $itemId,
                    'name' => $itemId === self::CONTAINER_ITEM_ID ? self::CONTAINER_NAME : 'None',
                ],
                $body,
            );
        });

        return $esiClient;
    }

    private function createRecordingLogger(): AbstractLogger
    {
        return new class ($this->loggedRecords) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function createSolarSystemRepository(): MapSolarSystemRepository
    {
        return $this->createStub(MapSolarSystemRepository::class);
    }

    private function createCharacter(): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);

        return $character;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAsset(int $itemId, int $typeId, int $quantity, bool $isSingleton): array
    {
        return [
            'item_id' => $itemId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'location_id' => self::JITA_STATION_ID,
            'location_type' => 'station',
            'location_flag' => 'Hangar',
            'is_singleton' => $isSingleton,
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
