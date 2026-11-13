<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Assets;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\PinnedContainer;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\PinnedContainerRepository;
use App\Service\Assets\PinnedContainerCatalog;
use App\Service\Assets\PinnedContainerEntry;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PinnedContainerCatalog::class)]
#[Group('issue-893')]
final class PinnedContainerCatalogTest extends TestCase
{
    private const int CORPORATION_ID = 98_893_001;
    private const int PERSONAL_BOX_ITEM_ID = 1_893_000_001;
    private const int PROJECT_BOX_ITEM_ID = 1_893_000_002;
    private const int SECOND_PROJECT_BOX_ITEM_ID = 1_893_000_003;
    private const int VANISHED_ITEM_ID = 1_893_000_099;
    private const int LOOSE_ITEM_ID = 1_893_000_050;

    public function testManualPinShowsLocationDivisionAndTheQuantityOfEverythingInside(): void
    {
        $user = $this->user();
        $catalog = $this->catalog($user, [
            $this->container(self::PERSONAL_BOX_ITEM_ID, 'Sylvain - Reactions', 'Jita IV - Moon 4', 'Reactions'),
            $this->content(1, self::PERSONAL_BOX_ITEM_ID, 10),
            $this->content(2, self::PERSONAL_BOX_ITEM_ID, 5),
            $this->content(3, 2, 7),
        ], pins: [new PinnedContainer($user, self::PERSONAL_BOX_ITEM_ID, 'Sylvain - Reactions')]);

        $entry = $this->onlyEntry($catalog->entriesOf($user));

        self::assertSame((string) self::PERSONAL_BOX_ITEM_ID, $entry->key);
        self::assertSame(self::PERSONAL_BOX_ITEM_ID, $entry->itemId);
        self::assertSame('Sylvain - Reactions', $entry->name);
        self::assertSame('Jita IV - Moon 4', $entry->locationName);
        self::assertSame('Reactions', $entry->divisionName);
        self::assertSame(22, $entry->itemCount);
        self::assertTrue($entry->found);
        self::assertNull($entry->projectId);
    }

    public function testManualPinMissingFromTheLastSyncStaysListedUnderItsRememberedName(): void
    {
        $user = $this->user();
        $catalog = $this->catalog($user, [], pins: [new PinnedContainer($user, self::VANISHED_ITEM_ID, 'Sylvain - Old box')]);

        $entry = $this->onlyEntry($catalog->entriesOf($user));

        self::assertFalse($entry->found);
        self::assertSame(self::VANISHED_ITEM_ID, $entry->itemId);
        self::assertSame('Sylvain - Old box', $entry->name);
        self::assertNull($entry->itemCount);
        self::assertNull($entry->locationName);
    }

    public function testContainersNamedLikeAnOwnedOpenProjectContainerArePinnedUnderThatProject(): void
    {
        $user = $this->user();
        $project = $this->project($user, 'Capital rush', ['Capital box']);
        $catalog = $this->catalog($user, [
            $this->container(self::PROJECT_BOX_ITEM_ID, '  capital BOX ', 'Jita IV - Moon 4', 'Capitals'),
            $this->content(1, self::PROJECT_BOX_ITEM_ID, 4),
            $this->container(self::PERSONAL_BOX_ITEM_ID, 'Unrelated', 'Jita IV - Moon 4', 'Capitals'),
        ], ownedProjects: [$project]);

        $entry = $this->onlyEntry($catalog->entriesOf($user));

        self::assertSame(self::PROJECT_BOX_ITEM_ID, $entry->itemId);
        self::assertSame($project->getId()->toRfc4122(), $entry->projectId);
        self::assertSame('Capital rush', $entry->projectName);
        self::assertSame(4, $entry->itemCount);
        self::assertTrue($entry->found);
    }

    public function testProjectsTheUserParticipatesInPinTheirContainersToo(): void
    {
        $user = $this->user();
        $project = $this->project($this->user(), 'Joint build', ['Capital box']);
        $catalog = $this->catalog($user, [
            $this->container(self::PROJECT_BOX_ITEM_ID, 'Capital box', 'Jita IV - Moon 4', 'Capitals'),
        ], participatingProjects: [$project]);

        $entry = $this->onlyEntry($catalog->entriesOf($user));

        self::assertSame('Joint build', $entry->projectName);
        self::assertSame(self::PROJECT_BOX_ITEM_ID, $entry->itemId);
    }

    public function testEveryContainerCarryingAProjectNameIsPinnedAndAProjectOnBothSidesIsListedOnce(): void
    {
        $user = $this->user();
        $project = $this->project($user, 'Capital rush', ['Capital box']);
        $catalog = $this->catalog($user, [
            $this->container(self::PROJECT_BOX_ITEM_ID, 'Capital box', 'Jita IV - Moon 4', 'Capitals'),
            $this->container(self::SECOND_PROJECT_BOX_ITEM_ID, 'Capital box', 'Amarr VIII', 'Capitals'),
        ], ownedProjects: [$project], participatingProjects: [$project]);

        $entries = $catalog->entriesOf($user);

        self::assertSame([self::PROJECT_BOX_ITEM_ID, self::SECOND_PROJECT_BOX_ITEM_ID], array_map(static fn (PinnedContainerEntry $entry): ?int => $entry->itemId, $entries));
    }

    public function testDeclaredProjectContainerWithoutAnyVisibleAssetIsListedAsNotFound(): void
    {
        $user = $this->user();
        $project = $this->project($user, 'Capital rush', ['Capital box']);
        $catalog = $this->catalog($user, [], ownedProjects: [$project]);

        $entry = $this->onlyEntry($catalog->entriesOf($user));

        self::assertFalse($entry->found);
        self::assertNull($entry->itemId);
        self::assertSame('Capital box', $entry->name);
        self::assertSame('Capital rush', $entry->projectName);
    }

    public function testManualPinOfAContainerAlsoPinnedByAProjectIsListedOnceUnderTheProject(): void
    {
        $user = $this->user();
        $project = $this->project($user, 'Capital rush', ['Capital box']);
        $catalog = $this->catalog($user, [
            $this->container(self::PROJECT_BOX_ITEM_ID, 'Capital box', 'Jita IV - Moon 4', 'Capitals'),
        ], pins: [new PinnedContainer($user, self::PROJECT_BOX_ITEM_ID, 'Capital box')], ownedProjects: [$project]);

        $entry = $this->onlyEntry($catalog->entriesOf($user));

        self::assertSame('Capital rush', $entry->projectName);
    }

    public function testProjectEntriesComeBeforeManualPins(): void
    {
        $user = $this->user();
        $project = $this->project($user, 'Capital rush', ['Capital box']);
        $catalog = $this->catalog($user, [
            $this->container(self::PERSONAL_BOX_ITEM_ID, 'Sylvain - Reactions', 'Jita IV - Moon 4', 'Reactions'),
            $this->container(self::PROJECT_BOX_ITEM_ID, 'Capital box', 'Jita IV - Moon 4', 'Capitals'),
        ], pins: [new PinnedContainer($user, self::PERSONAL_BOX_ITEM_ID, 'Sylvain - Reactions')], ownedProjects: [$project]);

        $itemIds = array_map(static fn (PinnedContainerEntry $entry): ?int => $entry->itemId, $catalog->entriesOf($user));

        self::assertSame([self::PROJECT_BOX_ITEM_ID, self::PERSONAL_BOX_ITEM_ID], $itemIds);
    }

    public function testUserWithoutMainCharacterSeesEveryPinAsNotFound(): void
    {
        $user = new User();
        $finder = $this->createMock(VisibleCorporationAssetsFinder::class);
        $finder->expects(self::never())->method('findVisibleAssets');
        $pins = $this->createStub(PinnedContainerRepository::class);
        $pins->method('findByUser')->willReturn([new PinnedContainer($user, self::PERSONAL_BOX_ITEM_ID, 'Sylvain - Reactions')]);
        $projects = $this->createStub(IndustryProjectRepository::class);

        $entry = $this->onlyEntry((new PinnedContainerCatalog($finder, $pins, $projects))->entriesOf($user));

        self::assertFalse($entry->found);
    }

    public function testVisibleAssetOfReturnsOnlyAssetsTheUserCanSee(): void
    {
        $user = $this->user();
        $catalog = $this->catalog($user, [$this->container(self::PERSONAL_BOX_ITEM_ID, 'Sylvain - Reactions', 'Jita IV - Moon 4', 'Reactions')]);

        self::assertSame(self::PERSONAL_BOX_ITEM_ID, $catalog->visibleAssetOf($user, self::PERSONAL_BOX_ITEM_ID)?->getItemId());
        self::assertNull($catalog->visibleAssetOf($user, self::VANISHED_ITEM_ID));
    }

    /**
     * @param list<CachedAsset> $visibleAssets
     * @param list<PinnedContainer> $pins
     * @param list<IndustryProject> $ownedProjects
     * @param list<IndustryProject> $participatingProjects
     */
    private function catalog(User $user, array $visibleAssets, array $pins = [], array $ownedProjects = [], array $participatingProjects = []): PinnedContainerCatalog
    {
        $finder = $this->createStub(VisibleCorporationAssetsFinder::class);
        $finder->method('findVisibleAssets')->willReturnCallback(
            static fn (int $corporationId, ?string $divisionName, ?User $viewer): array => $corporationId === self::CORPORATION_ID && $viewer === $user ? $visibleAssets : [],
        );
        $pinRepository = $this->createStub(PinnedContainerRepository::class);
        $pinRepository->method('findByUser')->willReturn($pins);
        $pinRepository->method('findByUserAndItemId')->willReturnCallback(
            static fn (User $pinOwner, int $itemId): ?PinnedContainer => array_values(array_filter($pins, static fn (PinnedContainer $pin): bool => $pin->getItemId() === $itemId))[0] ?? null,
        );
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findOpenOwnedBy')->willReturn($ownedProjects);
        $projectRepository->method('findOpenOfAcceptedParticipant')->willReturn($participatingProjects);

        return new PinnedContainerCatalog($finder, $pinRepository, $projectRepository);
    }

    private function user(): User
    {
        $character = (new Character())->setCorporationId(self::CORPORATION_ID);

        return (new User())->setMainCharacter($character);
    }

    /**
     * @param list<string> $containerNames
     */
    private function project(User $owner, string $name, array $containerNames): IndustryProject
    {
        return (new IndustryProject())->setOwner($owner)->setName($name)->setContainerNames($containerNames);
    }

    private function container(int $itemId, string $itemName, string $locationName, string $divisionName): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setItemName($itemName)
            ->setTypeId(17_366)
            ->setQuantity(1)
            ->setLocationId(60_003_760)
            ->setLocationName($locationName)
            ->setLocationType('station')
            ->setLocationFlag('CorpSAG2')
            ->setDivisionName($divisionName)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    private function content(int $itemId, int $containerItemId, int $quantity): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId(34)
            ->setQuantity($quantity)
            ->setLocationId($containerItemId)
            ->setLocationName('Jita IV - Moon 4')
            ->setLocationType('item')
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    /**
     * @param list<PinnedContainerEntry> $entries
     */
    private function onlyEntry(array $entries): PinnedContainerEntry
    {
        self::assertCount(1, $entries);

        return $entries[0];
    }
}
