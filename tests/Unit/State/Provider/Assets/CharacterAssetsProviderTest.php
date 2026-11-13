<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\AssetItemResource;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\State\Provider\Assets\CharacterAssetsProvider;
use App\Tests\Unit\Support\StubsSdeTypeClassification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CharacterAssetsProvider::class)]
#[Group('issue-863')]
final class CharacterAssetsProviderTest extends TestCase
{
    use StubsSdeTypeClassification;

    private const int JITA_STATION_ID = 60_003_760;

    private const int TYPE_RIFTER = 587;
    private const int TYPE_STATION_CONTAINER = 17_366;
    private const int TYPE_RIFTER_BLUEPRINT = 691;
    private const int TYPE_TRITANIUM = 34;
    private const int TYPE_ABSENT_FROM_SDE = 888_888;

    private const int RIFTER_ITEM_ID = 1_060_000_863_001;
    private const int CONTAINER_ITEM_ID = 1_060_000_863_002;
    private const int BLUEPRINT_ITEM_ID = 1_060_000_863_003;
    private const int ABSENT_TYPE_ITEM_ID = 1_060_000_863_004;
    private const int LEGACY_CONTAINER_ITEM_ID = 1_060_000_863_005;
    private const int LEGACY_TRITANIUM_ITEM_ID = 1_060_000_863_006;
    private const int NAMED_CONTAINER_ITEM_ID = 1_060_000_863_007;

    private Security&Stub $security;
    private CharacterRepository&Stub $characterRepository;
    private CachedAssetRepository&Stub $cachedAssetRepository;
    private RequestStack $requestStack;
    private CharacterAssetsProvider $provider;
    private Character $character;
    private User $user;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->characterRepository = $this->createStub(CharacterRepository::class);
        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->requestStack = new RequestStack();
        $this->requestStack->push(new Request());

        $this->user = $this->createStub(User::class);
        $this->character = $this->createStub(Character::class);
        $this->character->method('getId')->willReturn(Uuid::v4());
        $this->character->method('getUser')->willReturn($this->user);
        $this->characterRepository->method('find')->willReturn($this->character);
        $this->security->method('getUser')->willReturn($this->user);

        $this->provider = new CharacterAssetsProvider(
            $this->security,
            $this->characterRepository,
            $this->cachedAssetRepository,
            $this->createSdeClassification([
                self::TYPE_RIFTER => self::shipClassification(),
                self::TYPE_STATION_CONTAINER => self::cargoContainerClassification(),
                self::TYPE_RIFTER_BLUEPRINT => self::blueprintClassification(),
                self::TYPE_TRITANIUM => self::mineralClassification(),
            ]),
            $this->requestStack,
        );
    }

    public function testAnonymousReaderIsRejected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $provider = new CharacterAssetsProvider(
            $security,
            $this->characterRepository,
            $this->cachedAssetRepository,
            $this->createSdeClassification([]),
            $this->requestStack,
        );

        $this->expectException(UnauthorizedHttpException::class);

        $provider->provide(new Get(), ['characterId' => Uuid::v4()->toRfc4122()]);
    }

    public function testCharacterOfAnotherUserIsNotFound(): void
    {
        $characterRepository = $this->createStub(CharacterRepository::class);
        $otherCharacter = $this->createStub(Character::class);
        $otherCharacter->method('getUser')->willReturn($this->createStub(User::class));
        $characterRepository->method('find')->willReturn($otherCharacter);
        $provider = new CharacterAssetsProvider(
            $this->security,
            $characterRepository,
            $this->cachedAssetRepository,
            $this->createSdeClassification([]),
            $this->requestStack,
        );

        $this->expectException(NotFoundHttpException::class);

        $provider->provide(new Get(), ['characterId' => Uuid::v4()->toRfc4122()]);
    }

    public function testItemsExposeTheCategoryIdOfTheirTypeAndNullWhenTheTypeIsAbsentFromTheSde(): void
    {
        $this->cachedAssetRepository->method('findByCharacter')->willReturn([
            $this->characterAsset(self::RIFTER_ITEM_ID, self::TYPE_RIFTER, 1)->setIsSingleton(true),
            $this->characterAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1)->setIsSingleton(true),
            $this->characterAsset(self::BLUEPRINT_ITEM_ID, self::TYPE_RIFTER_BLUEPRINT, 1)->setIsSingleton(true),
            $this->characterAsset(self::LEGACY_TRITANIUM_ITEM_ID, self::TYPE_TRITANIUM, 1_500_000),
            $this->characterAsset(self::ABSENT_TYPE_ITEM_ID, self::TYPE_ABSENT_FROM_SDE, 1)->setIsSingleton(true),
        ]);

        $result = $this->provider->provide(new Get(), ['characterId' => Uuid::v4()->toRfc4122()]);

        self::assertSame(
            [
                self::RIFTER_ITEM_ID => 6,
                self::CONTAINER_ITEM_ID => 2,
                self::BLUEPRINT_ITEM_ID => 9,
                self::LEGACY_TRITANIUM_ITEM_ID => 4,
                self::ABSENT_TYPE_ITEM_ID => null,
            ],
            $this->mapByItemId($result->items, static fn (AssetItemResource $item): ?int => $item->categoryId),
        );
    }

    public function testItemsExposeWhetherTheirItemNameCanBeResolvedFromTheNameableTypeRule(): void
    {
        $this->cachedAssetRepository->method('findByCharacter')->willReturn([
            $this->characterAsset(self::RIFTER_ITEM_ID, self::TYPE_RIFTER, 1)->setIsSingleton(true),
            $this->characterAsset(self::CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1)->setIsSingleton(true),
            $this->characterAsset(self::NAMED_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1)->setIsSingleton(true)->setItemName('Ammo Box'),
            $this->characterAsset(self::BLUEPRINT_ITEM_ID, self::TYPE_RIFTER_BLUEPRINT, 1)->setIsSingleton(true),
            $this->characterAsset(self::ABSENT_TYPE_ITEM_ID, self::TYPE_ABSENT_FROM_SDE, 1)->setIsSingleton(true),
            $this->characterAsset(self::LEGACY_CONTAINER_ITEM_ID, self::TYPE_STATION_CONTAINER, 1),
            $this->characterAsset(self::LEGACY_TRITANIUM_ITEM_ID, self::TYPE_TRITANIUM, 1),
        ]);

        $result = $this->provider->provide(new Get(), ['characterId' => Uuid::v4()->toRfc4122()]);

        self::assertSame(
            [
                self::RIFTER_ITEM_ID => true,
                self::CONTAINER_ITEM_ID => true,
                self::NAMED_CONTAINER_ITEM_ID => false,
                self::BLUEPRINT_ITEM_ID => false,
                self::ABSENT_TYPE_ITEM_ID => false,
                self::LEGACY_CONTAINER_ITEM_ID => true,
                self::LEGACY_TRITANIUM_ITEM_ID => false,
            ],
            $this->mapByItemId($result->items, static fn (AssetItemResource $item): bool => $item->canResolveItemName),
        );
    }

    private function characterAsset(int $itemId, int $typeId, int $quantity): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName('Type ' . $typeId)
            ->setQuantity($quantity)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('station')
            ->setLocationFlag('Hangar')
            ->setSolarSystemId(30_000_142)
            ->setSolarSystemName('Jita');
    }

    /**
     * @param AssetItemResource[] $items
     * @return array<int, mixed>
     */
    private function mapByItemId(array $items, \Closure $valueOf): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[$item->itemId] = $valueOf($item);
        }

        return $mapped;
    }
}
