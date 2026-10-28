<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupContainerVerificationResource;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\User;
use App\Service\GroupIndustry\GroupIndustryContainerService;
use App\Tests\Integration\IntegrationTestCase;

final class GroupIndustryContainerServiceTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int JITA_STATION_ID = 60_003_760;
    private const int OFFICE_ITEM_ID = 1_050_000_000_001;
    private const int BUILD_CONTAINER_ITEM_ID = 1_050_000_000_100;
    private const int CONTAINER_TRITANIUM_ITEM_ID = 1_050_000_000_101;
    private const int CONTAINER_PYERITE_ITEM_ID = 1_050_000_000_102;
    private const int MINERALS_CONTAINER_ITEM_ID = 1_050_000_000_103;
    private const int NESTED_BOX_FIRST_ITEM_ID = 1_050_000_000_110;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const int STATION_CONTAINER = 17366;
    private const string BUILD_CONTAINER_NAME = 'Build';

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int TRITANIUM_IN_CONTAINER = 1_000;
    private const int PYERITE_IN_CONTAINER = 240;
    private const int TRITANIUM_REQUIRED = 500;
    private const int PYERITE_REQUIRED = 800;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createCorporationMember('Project Owner');
    }

    public function testContainerOutsideTheAllowedDivisionsIsNotFound(): void
    {
        $this->storeBuildContainerInHangar('CorpSAG2', 'Division 2');
        $this->allowDivisions([1]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [null, 'container_not_found', null],
                self::PYERITE => [null, 'container_not_found', null],
            ],
            $verification,
        );
    }

    public function testCheckIsUnknownWhileNoDivisionIsAllowed(): void
    {
        $this->storeBuildContainerInHangar('CorpSAG2', 'Division 2');
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [null, 'unknown', 'no_visible_division'],
                self::PYERITE => [null, 'unknown', 'no_visible_division'],
            ],
            $verification,
        );
    }

    public function testContainerInAnAllowedDivisionHoldsItsContents(): void
    {
        $this->storeBuildContainerInHangar('CorpSAG2', 'Division 2');
        $this->allowDivisions([2]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [1_000, 'verified', null],
                self::PYERITE => [240, 'partial', null],
            ],
            $verification,
        );
    }

    public function testContainerInTheDeliveriesHangarIsNotFoundEvenWithEveryDivisionAllowed(): void
    {
        $this->storeBuildContainerInDeliveries();
        $this->allowDivisions([1, 2, 3, 4, 5, 6, 7]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [null, 'container_not_found', null],
                self::PYERITE => [null, 'container_not_found', null],
            ],
            $verification,
        );
    }

    public function testContentsOfASubContainerCountTowardsTheContainer(): void
    {
        $this->createCorporationAsset(self::OFFICE_ITEM_ID, 27, 'Office', 1, self::JITA_STATION_ID, 'station', 'OfficeFolder', null);
        $this->createCorporationAsset(self::BUILD_CONTAINER_ITEM_ID, self::STATION_CONTAINER, 'Station Container', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Division 2', self::BUILD_CONTAINER_NAME);
        $this->createCorporationAsset(self::CONTAINER_TRITANIUM_ITEM_ID, self::TRITANIUM, 'Tritanium', 1_000, self::BUILD_CONTAINER_ITEM_ID, 'item', 'Unlocked', 'Division 2');
        $this->createCorporationAsset(self::MINERALS_CONTAINER_ITEM_ID, self::STATION_CONTAINER, 'Station Container', 1, self::BUILD_CONTAINER_ITEM_ID, 'item', 'Unlocked', 'Division 2', 'Minerals');
        $this->createCorporationAsset(self::CONTAINER_PYERITE_ITEM_ID, self::PYERITE, 'Pyerite', 240, self::MINERALS_CONTAINER_ITEM_ID, 'item', 'Unlocked', 'Division 2');
        $this->flushAndClear();
        $this->allowDivisions([2]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [1_000, 'verified', null],
                self::PYERITE => [240, 'partial', null],
            ],
            $verification,
        );
    }

    public function testContentsFiveLevelsBelowTheHangarAreCounted(): void
    {
        $this->createCorporationAsset(self::OFFICE_ITEM_ID, 27, 'Office', 1, self::JITA_STATION_ID, 'station', 'OfficeFolder', null);
        $this->createCorporationAsset(self::BUILD_CONTAINER_ITEM_ID, self::STATION_CONTAINER, 'Station Container', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Division 2', self::BUILD_CONTAINER_NAME);
        $parentItemId = self::BUILD_CONTAINER_ITEM_ID;
        foreach (['First box', 'Second box', 'Third box', 'Fourth box'] as $depth => $boxName) {
            $boxItemId = self::NESTED_BOX_FIRST_ITEM_ID + $depth;
            $this->createCorporationAsset($boxItemId, self::STATION_CONTAINER, 'Station Container', 1, $parentItemId, 'item', 'Unlocked', 'Division 2', $boxName);
            $parentItemId = $boxItemId;
        }
        $this->createCorporationAsset(self::CONTAINER_PYERITE_ITEM_ID, self::PYERITE, 'Pyerite', 800, $parentItemId, 'item', 'Unlocked', 'Division 2');
        $this->flushAndClear();
        $this->allowDivisions([2]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [0, 'missing', null],
                self::PYERITE => [800, 'verified', null],
            ],
            $verification,
        );
    }

    public function testCheckIsUnknownWhileTheCorporationAssetsWereNeverSynced(): void
    {
        $this->allowDivisions([2]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [null, 'unknown', 'corporation_assets_not_synced'],
                self::PYERITE => [null, 'unknown', 'corporation_assets_not_synced'],
            ],
            $verification,
        );
    }

    public function testAssetsOfAnotherCorporationDoNotMakeTheAssetsOfTheOwnerCorporationSynced(): void
    {
        $this->createCorporationAsset(self::BUILD_CONTAINER_ITEM_ID, self::STATION_CONTAINER, 'Station Container', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG2', 'Division 2', self::BUILD_CONTAINER_NAME, self::OTHER_CORPORATION_ID);
        $this->createCorporationAsset(self::CONTAINER_TRITANIUM_ITEM_ID, self::TRITANIUM, 'Tritanium', 1_000, self::BUILD_CONTAINER_ITEM_ID, 'item', 'Unlocked', 'Division 2', null, self::OTHER_CORPORATION_ID);
        $this->flushAndClear();
        $this->allowDivisions([2]);
        $project = $this->createProjectUsingBuildContainer();

        $verification = $this->verifyContainerOf($project);

        self::assertSame(
            [
                self::TRITANIUM => [null, 'unknown', 'corporation_assets_not_synced'],
                self::PYERITE => [null, 'unknown', 'corporation_assets_not_synced'],
            ],
            $verification,
        );
    }

    private function storeBuildContainerInHangar(string $hangarFlag, string $divisionName): void
    {
        $this->createCorporationAsset(self::OFFICE_ITEM_ID, 27, 'Office', 1, self::JITA_STATION_ID, 'station', 'OfficeFolder', null);
        $this->createCorporationAsset(self::BUILD_CONTAINER_ITEM_ID, 17366, 'Station Container', 1, self::OFFICE_ITEM_ID, 'item', $hangarFlag, $divisionName, self::BUILD_CONTAINER_NAME);
        $this->storeContentsOfBuildContainer();
    }

    private function storeBuildContainerInDeliveries(): void
    {
        $this->createCorporationAsset(self::BUILD_CONTAINER_ITEM_ID, 17366, 'Station Container', 1, self::JITA_STATION_ID, 'station', 'CorpDeliveries', null, self::BUILD_CONTAINER_NAME);
        $this->storeContentsOfBuildContainer();
    }

    private function storeContentsOfBuildContainer(): void
    {
        $this->createCorporationAsset(self::CONTAINER_TRITANIUM_ITEM_ID, self::TRITANIUM, 'Tritanium', self::TRITANIUM_IN_CONTAINER, self::BUILD_CONTAINER_ITEM_ID, 'item', 'Unlocked', null);
        $this->createCorporationAsset(self::CONTAINER_PYERITE_ITEM_ID, self::PYERITE, 'Pyerite', self::PYERITE_IN_CONTAINER, self::BUILD_CONTAINER_ITEM_ID, 'item', 'Unlocked', null);
        $this->flushAndClear();
    }

    /** @param int[] $divisionNumbers */
    private function allowDivisions(array $divisionNumbers): void
    {
        $this->em->persist((new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions($divisionNumbers)
            ->setConfiguredBy($this->em->getReference(User::class, $this->owner->getId())));
        $this->flushAndClear();
    }

    private function createProjectUsingBuildContainer(): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())
            ->setOwner($this->em->getReference(User::class, $this->owner->getId()))
            ->setName('Hull run')
            ->setContainerName(self::BUILD_CONTAINER_NAME);
        $this->em->persist($project);
        $this->addMaterial($project, self::TRITANIUM, 'Tritanium', self::TRITANIUM_REQUIRED);
        $this->addMaterial($project, self::PYERITE, 'Pyerite', self::PYERITE_REQUIRED);
        $this->flushAndClear();

        $reloadedProject = $this->em->find(GroupIndustryProject::class, $project->getId());
        \assert($reloadedProject instanceof GroupIndustryProject);

        return $reloadedProject;
    }

    private function addMaterial(GroupIndustryProject $project, int $typeId, string $typeName, int $requiredQuantity): void
    {
        $material = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(false);
        $project->addBomItem($material);
        $this->em->persist($material);
    }

    private function verifyContainerOf(GroupIndustryProject $project): array
    {
        $verification = [];
        foreach (self::getContainer()->get(GroupIndustryContainerService::class)->verifyContainer($project) as $material) {
            \assert($material instanceof GroupContainerVerificationResource);
            $verification[$material->typeId] = [$material->containerQuantity, $material->status, $material->unknownReason];
        }
        ksort($verification);

        return $verification;
    }

    private function createCorporationMember(string $name): User
    {
        $character = $this->createCharacter(name: $name);
        $user = $character->getUser();
        \assert($user instanceof User);
        $user->setMainCharacter($character);
        $this->em->flush();

        return $user;
    }

    private function createCorporationAsset(
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        int $locationId,
        string $locationType,
        string $locationFlag,
        ?string $divisionName,
        ?string $itemName = null,
        int $corporationId = self::CORPORATION_ID,
    ): void {
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType($locationType)
            ->setLocationFlag($locationFlag)
            ->setDivisionName($divisionName)
            ->setItemName($itemName)
            ->setSolarSystemId(30_000_142)
            ->setSolarSystemName('Jita')
            ->setCorporationId($corporationId)
            ->setIsCorporationAsset(true));
    }
}
