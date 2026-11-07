<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Assets;

use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(VisibleCorporationAssetsFinder::class)]
#[Group('issue-358')]
final class VisibleCorporationAssetsFinderTest extends TestCase
{
    private const int CORPORATION_ID = 98_358_001;
    private const string ALICE_ID = '0199a1f0-0000-7000-8000-000000358001';
    private const string BOB_ID = '0199a1f0-0000-7000-8000-000000358002';
    private const int ALL_MEMBERS_DIVISION = 2;
    private const int ALICE_ONLY_DIVISION = 1;
    private const int TRITANIUM_ITEM_ID = 1_058_000_001;
    private const int PYERITE_ITEM_ID = 1_058_000_002;

    private VisibleCorporationAssetsFinder $finder;

    protected function setUp(): void
    {
        $visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions([self::ALL_MEMBERS_DIVISION])
            ->setRestrictedDivisions([self::ALICE_ONLY_DIVISION => [self::ALICE_ID]]);
        $visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $visibilityRepository->method('findByCorporationId')->willReturnCallback(
            static fn (int $corporationId): ?CorpAssetVisibility => $corporationId === self::CORPORATION_ID ? $visibility : null,
        );

        $hangarAssets = [
            1 => $this->hangarAsset(self::TRITANIUM_ITEM_ID, 'CorpSAG1'),
            2 => $this->hangarAsset(self::PYERITE_ITEM_ID, 'CorpSAG2'),
        ];
        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('findByCorporationAndDivisions')->willReturnCallback(
            static fn (int $corporationId, array $divisionNumbers): array => array_values(array_intersect_key($hangarAssets, array_flip($divisionNumbers))),
        );
        $cachedAssetRepository->method('findContentsOfContainers')->willReturn([]);

        $this->finder = new VisibleCorporationAssetsFinder($cachedAssetRepository, $visibilityRepository);
    }

    public function testViewerListedForADivisionSeesItsAssetsAlongsideTheDivisionsOfAllMembers(): void
    {
        $assets = $this->finder->findVisibleAssets(self::CORPORATION_ID, null, $this->userWithId(self::ALICE_ID));

        self::assertSame([self::TRITANIUM_ITEM_ID, self::PYERITE_ITEM_ID], $this->itemIdsOf($assets));
    }

    public function testViewerNotListedForADivisionDoesNotSeeItsAssets(): void
    {
        $assets = $this->finder->findVisibleAssets(self::CORPORATION_ID, null, $this->userWithId(self::BOB_ID));

        self::assertSame([self::PYERITE_ITEM_ID], $this->itemIdsOf($assets));
    }

    public function testWithoutViewerOnlyTheDivisionsOfAllMembersAreRead(): void
    {
        $assets = $this->finder->findVisibleAssets(self::CORPORATION_ID);

        self::assertSame([self::PYERITE_ITEM_ID], $this->itemIdsOf($assets));
    }

    private function hangarAsset(int $itemId, string $locationFlag): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setLocationFlag($locationFlag)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    private function userWithId(string $userId): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString($userId));

        return $user;
    }

    private function itemIdsOf(array $assets): array
    {
        return array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $assets);
    }
}
