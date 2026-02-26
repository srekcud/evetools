<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\ApiResource\GroupIndustry\GroupContainerVerificationResource;
use App\Entity\CachedAsset;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\GroupIndustryBomItemRepository;
use App\Service\GroupIndustry\GroupIndustryContainerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupIndustryContainerService::class)]
class GroupIndustryContainerServiceTest extends TestCase
{
    private CachedAssetRepository&Stub $assetRepository;
    private GroupIndustryBomItemRepository&Stub $bomItemRepository;
    private GroupIndustryContainerService $service;

    protected function setUp(): void
    {
        $this->assetRepository = $this->createStub(CachedAssetRepository::class);
        $this->bomItemRepository = $this->createStub(GroupIndustryBomItemRepository::class);

        $this->service = new GroupIndustryContainerService(
            $this->assetRepository,
            $this->bomItemRepository,
        );
    }

    // ===========================================
    // Empty / no-container cases
    // ===========================================

    public function testReturnsEmptyArrayWhenNoMaterialItems(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([]);

        $result = $this->service->verifyContainer($project);

        $this->assertSame([], $result);
    }

    public function testReturnsAllUncheckedWhenNoContainerName(): void
    {
        $project = $this->createProjectWithContainer(null, 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('unchecked', $result[0]->status);
        $this->assertSame(0, $result[0]->containerQuantity);
    }

    public function testReturnsAllUncheckedWhenNoCorporationId(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', null);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('unchecked', $result[0]->status);
    }

    // ===========================================
    // Container not found in corp assets
    // ===========================================

    public function testReturnsUncheckedWhenContainerNotFoundInCorpAssets(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        // Corp assets exist but no container with matching name
        $someAsset = $this->createCorpAsset(1001, 'Some Other Container', 999, 0, null);
        $this->assetRepository->method('findByCorporationId')->willReturn([$someAsset]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('unchecked', $result[0]->status);
        $this->assertSame(0, $result[0]->containerQuantity);
    }

    // ===========================================
    // Verification statuses
    // ===========================================

    public function testVerifiedWhenContainerHasSufficientQuantity(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        $container = $this->createCorpAsset(5001, 'Materials Box', 0, 0, 'Materials Box');
        $itemInContainer = $this->createCorpAsset(5002, null, 34, 1500, null);
        // Item inside container: locationId = container's itemId
        $itemInContainer->setLocationId($container->getItemId());

        $this->assetRepository->method('findByCorporationId')->willReturn([$container, $itemInContainer]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('verified', $result[0]->status);
        $this->assertSame(1500, $result[0]->containerQuantity);
        $this->assertSame(1000, $result[0]->requiredQuantity);
    }

    public function testPartialWhenContainerHasInsufficientQuantity(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        $container = $this->createCorpAsset(5001, 'Materials Box', 0, 0, 'Materials Box');
        $itemInContainer = $this->createCorpAsset(5002, null, 34, 500, null);
        $itemInContainer->setLocationId($container->getItemId());

        $this->assetRepository->method('findByCorporationId')->willReturn([$container, $itemInContainer]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('partial', $result[0]->status);
        $this->assertSame(500, $result[0]->containerQuantity);
    }

    public function testUncheckedWhenMaterialNotInContainer(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        // Container exists but contains a different item
        $container = $this->createCorpAsset(5001, 'Materials Box', 0, 0, 'Materials Box');
        $differentItem = $this->createCorpAsset(5002, null, 35, 999, null); // Pyerite, not Tritanium
        $differentItem->setLocationId($container->getItemId());

        $this->assetRepository->method('findByCorporationId')->willReturn([$container, $differentItem]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('unchecked', $result[0]->status);
        $this->assertSame(0, $result[0]->containerQuantity);
    }

    // ===========================================
    // Multiple containers with same name
    // ===========================================

    public function testAggregatesQuantitiesAcrossMultipleContainersWithSameName(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        // Two containers with the same name in different stations
        $container1 = $this->createCorpAsset(5001, 'Materials Box', 0, 0, 'Materials Box');
        $container2 = $this->createCorpAsset(5003, 'Materials Box', 0, 0, 'Materials Box');

        $item1 = $this->createCorpAsset(5002, null, 34, 400, null);
        $item1->setLocationId($container1->getItemId());

        $item2 = $this->createCorpAsset(5004, null, 34, 700, null);
        $item2->setLocationId($container2->getItemId());

        $this->assetRepository->method('findByCorporationId')->willReturn([
            $container1, $container2, $item1, $item2,
        ]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(1, $result);
        $this->assertSame('verified', $result[0]->status);
        $this->assertSame(1100, $result[0]->containerQuantity); // 400 + 700
    }

    // ===========================================
    // Multiple BOM items
    // ===========================================

    public function testHandlesMultipleBomItemsWithMixedStatuses(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem1 = $this->createBomItem(34, 'Tritanium', 1000);
        $bomItem2 = $this->createBomItem(35, 'Pyerite', 500);
        $bomItem3 = $this->createBomItem(36, 'Mexallon', 200);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem1, $bomItem2, $bomItem3]);

        $container = $this->createCorpAsset(5001, 'Materials Box', 0, 0, 'Materials Box');
        $trit = $this->createCorpAsset(5002, null, 34, 2000, null);
        $trit->setLocationId($container->getItemId());
        $pye = $this->createCorpAsset(5003, null, 35, 100, null);
        $pye->setLocationId($container->getItemId());
        // No Mexallon in container

        $this->assetRepository->method('findByCorporationId')->willReturn([$container, $trit, $pye]);

        $result = $this->service->verifyContainer($project);

        $this->assertCount(3, $result);
        $this->assertSame('verified', $result[0]->status);   // Tritanium: 2000 >= 1000
        $this->assertSame('partial', $result[1]->status);     // Pyerite: 100 < 500
        $this->assertSame('unchecked', $result[2]->status);   // Mexallon: 0
    }

    // ===========================================
    // Resource structure
    // ===========================================

    public function testResourceHasCorrectFieldStructure(): void
    {
        $project = $this->createProjectWithContainer('Materials Box', 98000001);
        $bomItem = $this->createBomItem(34, 'Tritanium', 1000);
        $this->bomItemRepository->method('findMaterialsByProject')->willReturn([$bomItem]);

        $container = $this->createCorpAsset(5001, 'Materials Box', 0, 0, 'Materials Box');
        $item = $this->createCorpAsset(5002, null, 34, 500, null);
        $item->setLocationId($container->getItemId());

        $this->assetRepository->method('findByCorporationId')->willReturn([$container, $item]);

        $result = $this->service->verifyContainer($project);
        $resource = $result[0];

        $this->assertInstanceOf(GroupContainerVerificationResource::class, $resource);
        $this->assertNotEmpty($resource->bomItemId);
        $this->assertSame(34, $resource->typeId);
        $this->assertSame('Tritanium', $resource->typeName);
        $this->assertSame(1000, $resource->requiredQuantity);
        $this->assertSame(500, $resource->containerQuantity);
        $this->assertSame('partial', $resource->status);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createProjectWithContainer(?string $containerName, ?int $corporationId): GroupIndustryProject
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn($corporationId);

        $project = new GroupIndustryProject();
        $project->setOwner($owner);
        $project->setContainerName($containerName);

        return $project;
    }

    private function createBomItem(int $typeId, string $typeName, int $requiredQuantity): GroupIndustryBomItem
    {
        $item = new GroupIndustryBomItem();
        $item->setTypeId($typeId);
        $item->setTypeName($typeName);
        $item->setRequiredQuantity($requiredQuantity);
        $item->setIsJob(false);

        // Set a deterministic UUID via reflection
        $reflection = new \ReflectionProperty(GroupIndustryBomItem::class, 'id');
        $reflection->setValue($item, Uuid::v4());

        return $item;
    }

    private function createCorpAsset(
        int $itemId,
        ?string $locationName,
        int $typeId,
        int $quantity,
        ?string $itemName,
    ): CachedAsset {
        $asset = new CachedAsset();
        $asset->setItemId($itemId);
        $asset->setTypeId($typeId);
        $asset->setQuantity($quantity);
        $asset->setLocationId(0);
        $asset->setLocationName($locationName ?? 'Unknown');
        $asset->setLocationType('other');
        $asset->setTypeName('');
        $asset->setIsCorporationAsset(true);

        if ($itemName !== null) {
            $asset->setItemName($itemName);
        }

        return $asset;
    }
}
