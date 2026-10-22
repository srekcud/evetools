<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\CharacterAssetsResource;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Provider\Assets\CharacterAssetsProvider;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[Group('issue-115')]
final class CharacterAssetsProviderTest extends IntegrationTestCase
{
    private const int JITA_STATION_ID = 60_003_760;
    private const int TRITANIUM_ITEM_ID = 1_050_000_000_101;
    private const int UNKNOWN_TYPE_ITEM_ID = 1_050_000_000_102;

    private User $user;
    private string $characterId;

    protected function setUp(): void
    {
        parent::setUp();
        $character = $this->createCharacter();
        $this->user = $character->getUser();
        $this->createCharacterAsset($character, self::TRITANIUM_ITEM_ID, 34, 'Tritanium', 8_800);
        $this->createCharacterAsset($character, self::UNKNOWN_TYPE_ITEM_ID, 9848, null, 6_300);
        $this->flushAndClear();
        $this->characterId = $character->getId()->toRfc4122();
    }

    public function testAnAssetWhoseTypeNameIsUnknownIsExposedWithoutTypeName(): void
    {
        $result = $this->listCharacterAssets();

        $itemsByItemId = [];
        foreach ($result->items as $item) {
            $itemsByItemId[$item->itemId] = $item;
        }

        self::assertSame(2, $result->total);
        self::assertSame('Tritanium', $itemsByItemId[self::TRITANIUM_ITEM_ID]->typeName);
        self::assertNull($itemsByItemId[self::UNKNOWN_TYPE_ITEM_ID]->typeName);
        self::assertSame(9848, $itemsByItemId[self::UNKNOWN_TYPE_ITEM_ID]->typeId);
        self::assertSame(6_300, $itemsByItemId[self::UNKNOWN_TYPE_ITEM_ID]->quantity);
    }

    public function testLocationFilterExposesAnAssetWhoseTypeNameIsUnknownWithoutTypeName(): void
    {
        $result = $this->listCharacterAssets(self::JITA_STATION_ID);

        self::assertSame(
            ['Tritanium', null],
            array_map(static fn (AssetItemResource $item): ?string => $item->typeName, $result->items),
        );
    }

    private function listCharacterAssets(?int $locationId = null): CharacterAssetsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->em->find(User::class, $this->user->getId()));

        $requestStack = new RequestStack();
        $requestStack->push(new Request($locationId !== null ? ['locationId' => (string) $locationId] : []));

        $provider = new CharacterAssetsProvider(
            $security,
            self::getContainer()->get(CharacterRepository::class),
            self::getContainer()->get(CachedAssetRepository::class),
            self::getContainer()->get(InvTypeRepository::class),
            $requestStack,
        );

        return $provider->provide(new Get(), ['characterId' => $this->characterId]);
    }

    private function createCharacterAsset(Character $character, int $itemId, int $typeId, ?string $typeName, int $quantity): void
    {
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag('Hangar')
            ->setSolarSystemId(30_000_142)
            ->setSolarSystemName('Jita')
            ->setCharacter($character)
            ->setIsCorporationAsset(false));
    }
}
