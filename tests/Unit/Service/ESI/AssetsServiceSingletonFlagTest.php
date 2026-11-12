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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AssetsService::class)]
#[Group('issue-784')]
final class AssetsServiceSingletonFlagTest extends TestCase
{
    private const int EVE_CHARACTER_ID = 2_113_784_021;
    private const int CORPORATION_ID = 98_784_021;
    private const int JITA_STATION_ID = 60_003_760;
    private const int RIFTER_TYPE_ID = 587;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int RIFTER_ITEM_ID = 1_050_000_784_201;
    private const int TRITANIUM_ITEM_ID = 1_050_000_784_202;

    public function testCharacterAssetsCarryTheSingletonFlagOfEveryEsiRow(): void
    {
        $assets = $this->indexByItemId($this->assetsService([
            $this->rawAsset(self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 1, true),
            $this->rawAsset(self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 500, false),
        ])->getCharacterAssets($this->character()));

        self::assertTrue($assets[self::RIFTER_ITEM_ID]->isSingleton);
        self::assertFalse($assets[self::TRITANIUM_ITEM_ID]->isSingleton);
    }

    public function testCorporationAssetsCarryTheSingletonFlagOfEveryEsiRow(): void
    {
        $assets = $this->indexByItemId($this->assetsService([
            $this->rawAsset(self::RIFTER_ITEM_ID, self::RIFTER_TYPE_ID, 1, true),
            $this->rawAsset(self::TRITANIUM_ITEM_ID, self::TRITANIUM_TYPE_ID, 500, false),
        ])->getCorporationAssets($this->character()));

        self::assertTrue($assets[self::RIFTER_ITEM_ID]->isSingleton);
        self::assertFalse($assets[self::TRITANIUM_ITEM_ID]->isSingleton);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rowsWithAMalformedSingletonFlag(): iterable
    {
        yield 'is_singleton absent' => [['item_id' => self::RIFTER_ITEM_ID, 'type_id' => self::RIFTER_TYPE_ID, 'quantity' => 1, 'location_id' => self::JITA_STATION_ID, 'location_type' => 'station', 'location_flag' => 'Hangar']];
        yield 'is_singleton is a string' => [self::rowWithFlag('true')];
        yield 'is_singleton is an integer' => [self::rowWithFlag(1)];
        yield 'is_singleton is a float' => [self::rowWithFlag(1.0)];
        yield 'is_singleton is null' => [self::rowWithFlag(null)];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('rowsWithAMalformedSingletonFlag')]
    public function testRowWithAMalformedSingletonFlagRaisesAnExplicitError(array $row): void
    {
        $this->expectException(EsiApiException::class);
        $this->expectExceptionMessage('is_singleton');

        $this->assetsService([$row])->getCharacterAssets($this->character());
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowWithFlag(mixed $flag): array
    {
        return [
            'item_id' => self::RIFTER_ITEM_ID,
            'type_id' => self::RIFTER_TYPE_ID,
            'quantity' => 1,
            'location_id' => self::JITA_STATION_ID,
            'location_type' => 'station',
            'location_flag' => 'Hangar',
            'is_singleton' => $flag,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rawAssets
     */
    private function assetsService(array $rawAssets): AssetsService
    {
        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturn($rawAssets);
        $esiClient->method('get')->willReturn(['name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', 'system_id' => 30_000_142]);
        $esiClient->method('post')->willReturn([]);

        return new AssetsService(
            $esiClient,
            $this->createStub(MapSolarSystemRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new TypeNameResolver($this->createStub(InvTypeRepository::class)),
        );
    }

    private function character(): Character
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

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
