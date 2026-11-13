<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\CharacterAssetsResource;
use App\ApiResource\Assets\CorporationAssetsResource;
use App\ApiResource\Assets\SyncStatusResource;
use App\Serializer\AssetListNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetListNormalizer::class)]
#[Group('issue-874')]
final class AssetListNormalizerTest extends TestCase
{
    private const array ITEM_FIELDS = [
        'id',
        'itemId',
        'typeId',
        'typeName',
        'categoryId',
        'quantity',
        'locationId',
        'locationName',
        'locationType',
        'locationFlag',
        'solarSystemId',
        'solarSystemName',
        'itemName',
        'divisionName',
        'isSingleton',
        'canResolveItemName',
        'cachedAt',
    ];

    private AssetListNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AssetListNormalizer();
    }

    public function testCorporationListKeepsTheFieldsTheInventoryReads(): void
    {
        $resource = new CorporationAssetsResource();
        $resource->total = 2;
        $resource->nextRefreshAt = '2026-11-13T12:40:00+00:00';
        $resource->items = [self::tritaniumStack(), self::namedContainerInSpace()];

        $payload = $this->normalizer->normalize($resource, 'jsonld');

        self::assertSame(['id', 'total', 'nextRefreshAt', 'items'], array_keys($payload));
        self::assertSame('corporation', $payload['id']);
        self::assertSame(2, $payload['total']);
        self::assertSame('2026-11-13T12:40:00+00:00', $payload['nextRefreshAt']);
        self::assertCount(2, $payload['items']);
    }

    public function testCharacterListKeepsTheFieldsTheInventoryReads(): void
    {
        $resource = new CharacterAssetsResource();
        $resource->characterId = '0198f0a2-6f3b-7c1e-9a55-3d2a1b9c0e11';
        $resource->total = 1;
        $resource->items = [self::tritaniumStack()];

        $payload = $this->normalizer->normalize($resource, 'json');

        self::assertSame(['characterId', 'total', 'items'], array_keys($payload));
        self::assertSame('0198f0a2-6f3b-7c1e-9a55-3d2a1b9c0e11', $payload['characterId']);
        self::assertSame(1, $payload['total']);
        self::assertCount(1, $payload['items']);
    }

    public function testCorporationWithoutRefreshScheduleKeepsNextRefreshAtAsNull(): void
    {
        $resource = new CorporationAssetsResource();

        $payload = $this->normalizer->normalize($resource, 'json');

        self::assertArrayHasKey('nextRefreshAt', $payload);
        self::assertNull($payload['nextRefreshAt']);
        self::assertSame([], $payload['items']);
        self::assertSame(0, $payload['total']);
    }

    public function testItemExposesExactlyTheInventoryFieldsWithoutLinkedDataKeys(): void
    {
        $resource = new CharacterAssetsResource();
        $resource->items = [self::tritaniumStack()];

        $item = $this->normalizer->normalize($resource, 'jsonld')['items'][0];

        self::assertSame(self::ITEM_FIELDS, array_keys($item));
        self::assertArrayNotHasKey('@id', $item);
        self::assertArrayNotHasKey('@type', $item);
    }

    public function testItemKeepsTheTypeOfEveryField(): void
    {
        $resource = new CharacterAssetsResource();
        $resource->items = [self::namedContainerInSpace()];

        $item = $this->normalizer->normalize($resource, 'json')['items'][0];

        self::assertSame('0198f0a2-6f3b-7c1e-9a55-3d2a1b9c0e22', $item['id']);
        self::assertSame(1_050_000_874_002, $item['itemId']);
        self::assertSame(17_366, $item['typeId']);
        self::assertSame('Station Container', $item['typeName']);
        self::assertSame(2, $item['categoryId']);
        self::assertSame(1, $item['quantity']);
        self::assertSame(30_000_142, $item['locationId']);
        self::assertSame('Jita', $item['locationName']);
        self::assertSame('solar_system', $item['locationType']);
        self::assertSame('Hangar', $item['locationFlag']);
        self::assertSame(30_000_142, $item['solarSystemId']);
        self::assertSame('Jita', $item['solarSystemName']);
        self::assertSame('Mining supplies', $item['itemName']);
        self::assertSame('Materials', $item['divisionName']);
        self::assertTrue($item['isSingleton']);
        self::assertTrue($item['canResolveItemName']);
        self::assertSame('2026-11-13T12:10:00+00:00', $item['cachedAt']);
    }

    public function testItemKeepsUnknownValuesAsNullInsteadOfDroppingThem(): void
    {
        $resource = new CharacterAssetsResource();
        $resource->items = [self::tritaniumStack()];

        $item = $this->normalizer->normalize($resource, 'json')['items'][0];

        foreach (['categoryId', 'solarSystemId', 'solarSystemName', 'itemName', 'divisionName', 'isSingleton'] as $unknownField) {
            self::assertArrayHasKey($unknownField, $item);
            self::assertNull($item[$unknownField]);
        }
        self::assertFalse($item['canResolveItemName']);
    }

    public function testItemOrderFollowsTheResource(): void
    {
        $resource = new CorporationAssetsResource();
        $resource->items = [self::namedContainerInSpace(), self::tritaniumStack()];

        $payload = $this->normalizer->normalize($resource, 'json');

        self::assertSame([17_366, 34], array_column($payload['items'], 'typeId'));
        self::assertTrue(array_is_list($payload['items']));
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function supportedLists(): iterable
    {
        yield 'corporation list' => [new CorporationAssetsResource()];
        yield 'character list' => [new CharacterAssetsResource()];
    }

    #[DataProvider('supportedLists')]
    public function testSupportsAssetListsInJsonAndJsonLd(object $resource): void
    {
        self::assertTrue($this->normalizer->supportsNormalization($resource, 'json'));
        self::assertTrue($this->normalizer->supportsNormalization($resource, 'jsonld'));
    }

    #[DataProvider('supportedLists')]
    public function testLeavesOtherFormatsToTheirOwnNormalizers(object $resource): void
    {
        self::assertFalse($this->normalizer->supportsNormalization($resource, 'csv'));
        self::assertFalse($this->normalizer->supportsNormalization($resource, null));
    }

    public function testLeavesOtherResourcesToTheApiPlatformSerializer(): void
    {
        self::assertFalse($this->normalizer->supportsNormalization(new SyncStatusResource(), 'jsonld'));
        self::assertFalse($this->normalizer->supportsNormalization(new AssetItemResource(), 'jsonld'));
        self::assertFalse($this->normalizer->supportsNormalization(['items' => []], 'jsonld'));
    }

    public function testDeclaresTheTwoListResourcesAsSupportedTypes(): void
    {
        self::assertSame(
            [CorporationAssetsResource::class => true, CharacterAssetsResource::class => true],
            $this->normalizer->getSupportedTypes('jsonld'),
        );
    }

    private static function tritaniumStack(): AssetItemResource
    {
        $item = new AssetItemResource();
        $item->id = '0198f0a2-6f3b-7c1e-9a55-3d2a1b9c0e11';
        $item->itemId = 1_050_000_874_001;
        $item->typeId = 34;
        $item->typeName = 'Tritanium';
        $item->quantity = 1_000;
        $item->locationId = 60_003_760;
        $item->locationName = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
        $item->locationType = 'station';
        $item->locationFlag = 'Hangar';
        $item->cachedAt = '2026-11-13T12:10:00+00:00';

        return $item;
    }

    private static function namedContainerInSpace(): AssetItemResource
    {
        $item = new AssetItemResource();
        $item->id = '0198f0a2-6f3b-7c1e-9a55-3d2a1b9c0e22';
        $item->itemId = 1_050_000_874_002;
        $item->typeId = 17_366;
        $item->typeName = 'Station Container';
        $item->categoryId = 2;
        $item->quantity = 1;
        $item->locationId = 30_000_142;
        $item->locationName = 'Jita';
        $item->locationType = 'solar_system';
        $item->locationFlag = 'Hangar';
        $item->solarSystemId = 30_000_142;
        $item->solarSystemName = 'Jita';
        $item->itemName = 'Mining supplies';
        $item->divisionName = 'Materials';
        $item->isSingleton = true;
        $item->canResolveItemName = true;
        $item->cachedAt = '2026-11-13T12:10:00+00:00';

        return $item;
    }
}
