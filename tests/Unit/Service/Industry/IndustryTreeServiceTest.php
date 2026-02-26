<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryTreeService::class)]
class IndustryTreeServiceTest extends TestCase
{
    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private IndustryActivityMaterialRepository&MockObject $activityMaterialRepository;
    private InvTypeRepository&MockObject $invTypeRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryBonusService&MockObject $bonusService;
    private IndustryTreeService $service;

    protected function setUp(): void
    {
        $this->activityProductRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->activityMaterialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->bonusService = $this->createMock(IndustryBonusService::class);

        // Default: hasCopyActivity returns false (no copy row)
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->service = new IndustryTreeService(
            $this->activityProductRepository,
            $this->activityMaterialRepository,
            $this->invTypeRepository,
            $this->entityManager,
            $this->bonusService,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createProduct(int $blueprintTypeId, int $activityId, int $productTypeId, int $quantity, ?float $probability = null): IndustryActivityProduct
    {
        $product = new IndustryActivityProduct();
        $product->setTypeId($blueprintTypeId);
        $product->setActivityId($activityId);
        $product->setProductTypeId($productTypeId);
        $product->setQuantity($quantity);
        if ($probability !== null) {
            $product->setProbability($probability);
        }

        return $product;
    }

    private function createMaterial(int $blueprintTypeId, int $activityId, int $materialTypeId, int $quantity): IndustryActivityMaterial
    {
        $material = new IndustryActivityMaterial();
        $material->setTypeId($blueprintTypeId);
        $material->setActivityId($activityId);
        $material->setMaterialTypeId($materialTypeId);
        $material->setQuantity($quantity);

        return $material;
    }

    private function createInvType(int $typeId, string $typeName): InvType
    {
        $type = $this->createMock(InvType::class);
        $type->method('getTypeName')->willReturn($typeName);

        return $type;
    }

    /**
     * Set up the repository to return a product for findBlueprintForProduct.
     * Uses a callback to handle multiple product lookups.
     *
     * @param array<int, array{blueprint: IndustryActivityProduct|null, reaction: IndustryActivityProduct|null}> $productMap
     */
    private function setupProductLookup(array $productMap): void
    {
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $productTypeId, int $activityId) use ($productMap): ?IndustryActivityProduct {
                if (!isset($productMap[$productTypeId])) {
                    return null;
                }

                if ($activityId === IndustryActivityType::Manufacturing->value) {
                    return $productMap[$productTypeId]['blueprint'] ?? null;
                }
                if ($activityId === IndustryActivityType::Reaction->value) {
                    return $productMap[$productTypeId]['reaction'] ?? null;
                }

                return null;
            });
    }

    // ===========================================
    // T1 item: flat tree with materials
    // ===========================================

    public function testBuildProductionTreeT1ItemReturnsFlatTree(): void
    {
        // Rifter (587) is manufactured from blueprint 586
        $rifterProduct = $this->createProduct(586, IndustryActivityType::Manufacturing->value, 587, 1);

        $this->setupProductLookup([
            587 => ['blueprint' => $rifterProduct, 'reaction' => null],
        ]);

        // Materials for Rifter: Tritanium (34) and Pyerite (35)
        $tritaniumMat = $this->createMaterial(586, IndustryActivityType::Manufacturing->value, 34, 25000);
        $pyeriteMat = $this->createMaterial(586, IndustryActivityType::Manufacturing->value, 35, 5000);

        $this->activityMaterialRepository
            ->method('findBy')
            ->willReturn([$tritaniumMat, $pyeriteMat]);

        // Type names
        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(function (int $typeId): ?InvType {
                return match ($typeId) {
                    587 => $this->createInvType(587, 'Rifter'),
                    34 => $this->createInvType(34, 'Tritanium'),
                    35 => $this->createInvType(35, 'Pyerite'),
                    default => null,
                };
            });

        $tree = $this->service->buildProductionTree(587, 1, 0);

        $this->assertSame(586, $tree['blueprintTypeId']);
        $this->assertSame(587, $tree['productTypeId']);
        $this->assertSame('Rifter', $tree['productTypeName']);
        $this->assertSame(1, $tree['runs']);
        $this->assertSame(1, $tree['outputPerRun']);
        $this->assertSame(0, $tree['depth']);
        $this->assertSame('manufacturing', $tree['activityType']);
        $this->assertCount(2, $tree['materials']);

        // Raw materials are not buildable
        $this->assertFalse($tree['materials'][0]['isBuildable']);
        $this->assertSame(34, $tree['materials'][0]['typeId']);
        $this->assertSame('Tritanium', $tree['materials'][0]['typeName']);
        $this->assertSame(25000, $tree['materials'][0]['quantity']);
    }

    // ===========================================
    // T2 item: nested tree with intermediate components
    // ===========================================

    public function testBuildProductionTreeT2ItemReturnsNestedTree(): void
    {
        // Simulated T2 item (typeId=11186) produced by blueprint 11185
        // with intermediate component (typeId=11399) that is also manufactured
        $t2Product = $this->createProduct(11185, IndustryActivityType::Manufacturing->value, 11186, 1);
        $intermediateProduct = $this->createProduct(11398, IndustryActivityType::Manufacturing->value, 11399, 1);

        $this->setupProductLookup([
            11186 => ['blueprint' => $t2Product, 'reaction' => null],
            11399 => ['blueprint' => $intermediateProduct, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null], // raw material
        ]);

        // Materials for T2 product: intermediate component + Tritanium
        $intermediateMat = $this->createMaterial(11185, IndustryActivityType::Manufacturing->value, 11399, 5);
        $tritaniumMat = $this->createMaterial(11185, IndustryActivityType::Manufacturing->value, 34, 1000);

        // Materials for intermediate: just Tritanium
        $intermediateTritanium = $this->createMaterial(11398, IndustryActivityType::Manufacturing->value, 34, 200);

        $this->activityMaterialRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($intermediateMat, $tritaniumMat, $intermediateTritanium): array {
                $typeId = $criteria['typeId'];
                if ($typeId === 11185) {
                    return [$intermediateMat, $tritaniumMat];
                }
                if ($typeId === 11398) {
                    return [$intermediateTritanium];
                }

                return [];
            });

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(function (int $typeId): ?InvType {
                return match ($typeId) {
                    11186 => $this->createInvType(11186, 'T2 Widget'),
                    11399 => $this->createInvType(11399, 'Intermediate Component'),
                    34 => $this->createInvType(34, 'Tritanium'),
                    default => null,
                };
            });

        $tree = $this->service->buildProductionTree(11186, 1, 10);

        // Root node
        $this->assertSame('manufacturing', $tree['activityType']);
        $this->assertCount(2, $tree['materials']);

        // Intermediate component is buildable and has a nested blueprint
        $intermediateNode = $tree['materials'][0];
        $this->assertTrue($intermediateNode['isBuildable']);
        $this->assertSame(11399, $intermediateNode['typeId']);
        $this->assertArrayHasKey('blueprint', $intermediateNode);

        // Nested blueprint depth is 1
        $nestedBlueprint = $intermediateNode['blueprint'];
        $this->assertSame(1, $nestedBlueprint['depth']);
        $this->assertSame('manufacturing', $nestedBlueprint['activityType']);

        // Intermediate component uses ME 10 (default for intermediate manufacturing)
        $this->assertCount(1, $nestedBlueprint['materials']);
    }

    // ===========================================
    // Reaction item (activity_id=11)
    // ===========================================

    public function testBuildProductionTreeReactionItem(): void
    {
        // Reaction: Reinforced Carbon Fiber (typeId=30003) from formula 30002
        $reactionProduct = $this->createProduct(30002, IndustryActivityType::Reaction->value, 30003, 200);

        $this->setupProductLookup([
            30003 => ['blueprint' => null, 'reaction' => $reactionProduct],
            100 => ['blueprint' => null, 'reaction' => null], // raw input
            101 => ['blueprint' => null, 'reaction' => null], // raw input
        ]);

        $input1 = $this->createMaterial(30002, IndustryActivityType::Reaction->value, 100, 200);
        $input2 = $this->createMaterial(30002, IndustryActivityType::Reaction->value, 101, 200);

        $this->activityMaterialRepository
            ->method('findBy')
            ->willReturn([$input1, $input2]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(function (int $typeId): ?InvType {
                return match ($typeId) {
                    30003 => $this->createInvType(30003, 'Reinforced Carbon Fiber'),
                    100 => $this->createInvType(100, 'Carbon Fiber'),
                    101 => $this->createInvType(101, 'Thermosetting Polymer'),
                    default => null,
                };
            });

        // 400 units requested, 200 per run => 2 runs
        $tree = $this->service->buildProductionTree(30003, 400, 0);

        $this->assertSame('reaction', $tree['activityType']);
        $this->assertSame(2, $tree['runs']);
        $this->assertSame(200, $tree['outputPerRun']);
        $this->assertSame(400, $tree['quantity']);

        // Reactions do not apply ME, quantities should be base * runs
        foreach ($tree['materials'] as $mat) {
            $this->assertSame(400, $mat['quantity']); // 200 * 2 runs
        }
    }

    // ===========================================
    // ME bonus reduces material quantities
    // ===========================================

    public function testMEBonusReducesMaterialQuantities(): void
    {
        $product = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);

        $this->setupProductLookup([
            200 => ['blueprint' => $product, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null],
        ]);

        $material = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 34, 1000);
        $this->activityMaterialRepository->method('findBy')->willReturn([$material]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        // ME 10 reduces by 10%: 1000 * 10 runs * 0.9 = 9000
        $tree = $this->service->buildProductionTree(200, 10, 10);

        $this->assertSame(9000, $tree['materials'][0]['quantity']);
    }

    public function testME0NoReduction(): void
    {
        $product = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);

        $this->setupProductLookup([
            200 => ['blueprint' => $product, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null],
        ]);

        $material = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 34, 1000);
        $this->activityMaterialRepository->method('findBy')->willReturn([$material]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        // ME 0: 1000 * 10 runs * 1.0 = 10000
        $tree = $this->service->buildProductionTree(200, 10, 0);

        $this->assertSame(10000, $tree['materials'][0]['quantity']);
    }

    // ===========================================
    // Structure bonus: additional material reduction
    // ===========================================

    public function testStructureBonusReducesMaterials(): void
    {
        $user = $this->createMock(User::class);
        $product = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);

        $this->setupProductLookup([
            200 => ['blueprint' => $product, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null],
        ]);

        $material = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 34, 100);
        $this->activityMaterialRepository->method('findBy')->willReturn([$material]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        // Structure bonus: base=1%, rig=4.2%
        $this->bonusService->method('findBestStructureForProduct')->willReturn([
            'bonus' => ['base' => 1.0, 'rig' => 4.2, 'total' => 5.16],
            'structure' => null,
            'category' => 'ship',
        ]);

        $this->bonusService->method('getCategoryForProduct')->willReturn('ship');
        $this->bonusService->method('findBestStructureForCategory')->willReturn([
            'bonus' => ['base' => 1.0, 'rig' => 4.2, 'total' => 5.16],
            'structure' => null,
            'category' => 'ship',
        ]);

        // ME 10, 26 runs, base 100, structure base 1%, rig 4.2%
        // 100 * 26 * 0.9 * 0.99 * 0.958 = 2219.30 -> ceil -> 2220
        $tree = $this->service->buildProductionTree(200, 26, 10, [], $user);

        $this->assertSame(2220, $tree['materials'][0]['quantity']);
        $this->assertSame(5.16, $tree['structureBonus']);
    }

    // ===========================================
    // Excluded type IDs skip expansion
    // ===========================================

    public function testExcludedTypeIdsAreNotExpanded(): void
    {
        $product = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);
        // Component 300 is buildable but will be excluded
        $componentProduct = $this->createProduct(299, IndustryActivityType::Manufacturing->value, 300, 1);

        $this->setupProductLookup([
            200 => ['blueprint' => $product, 'reaction' => null],
            300 => ['blueprint' => $componentProduct, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null],
        ]);

        $componentMat = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 300, 10);
        $rawMat = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 34, 500);
        $this->activityMaterialRepository->method('findBy')->willReturn([$componentMat, $rawMat]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        // Exclude type 300 - it should NOT be expanded
        $tree = $this->service->buildProductionTree(200, 1, 0, [300]);

        $this->assertFalse($tree['materials'][0]['isBuildable']);
        $this->assertArrayNotHasKey('blueprint', $tree['materials'][0]);
        $this->assertSame(300, $tree['materials'][0]['typeId']);
    }

    // ===========================================
    // Missing blueprint throws RuntimeException
    // ===========================================

    public function testMissingBlueprintThrowsException(): void
    {
        // No product found for this typeId
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No blueprint or reaction found for product type ID 99999');

        $this->service->buildProductionTree(99999, 1, 0);
    }

    // ===========================================
    // Minimum quantity is runs
    // ===========================================

    public function testMinimumQuantityIsRuns(): void
    {
        $product = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);

        $this->setupProductLookup([
            200 => ['blueprint' => $product, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null],
        ]);

        // Base quantity 1, with ME10 => 1 * 10 * 0.9 = 9, but min is 10 (runs)
        $material = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 34, 1);
        $this->activityMaterialRepository->method('findBy')->willReturn([$material]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        $tree = $this->service->buildProductionTree(200, 10, 10);

        // min(runs=10, ceil(1*10*0.9)) = max(10, 9) = 10
        $this->assertSame(10, $tree['materials'][0]['quantity']);
    }

    // ===========================================
    // Multiple runs with output > 1 per run
    // ===========================================

    public function testOutputPerRunAffectsRunsCalculation(): void
    {
        // Output 3 per run, requesting 9 units => 3 runs
        $product = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 3);

        $this->setupProductLookup([
            200 => ['blueprint' => $product, 'reaction' => null],
            34 => ['blueprint' => null, 'reaction' => null],
        ]);

        $material = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 34, 50);
        $this->activityMaterialRepository->method('findBy')->willReturn([$material]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        $tree = $this->service->buildProductionTree(200, 9, 0);

        $this->assertSame(3, $tree['outputPerRun']);
        $this->assertSame(3, $tree['runs']); // ceil(9/3) = 3
        $this->assertSame(150, $tree['materials'][0]['quantity']); // 50 * 3
    }

    // ===========================================
    // Reactions do NOT apply ME
    // ===========================================

    public function testReactionDoesNotApplyME(): void
    {
        $reactionProduct = $this->createProduct(30002, IndustryActivityType::Reaction->value, 30003, 100);

        $this->setupProductLookup([
            30003 => ['blueprint' => null, 'reaction' => $reactionProduct],
            100 => ['blueprint' => null, 'reaction' => null],
        ]);

        $material = $this->createMaterial(30002, IndustryActivityType::Reaction->value, 100, 200);
        $this->activityMaterialRepository->method('findBy')->willReturn([$material]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        // Even though ME 10 is passed, reactions should ignore it
        $tree = $this->service->buildProductionTree(30003, 200, 10);

        // 200 qty, 100 per run = 2 runs, 200 * 2 = 400 (no ME reduction)
        $this->assertSame(400, $tree['materials'][0]['quantity']);
    }

    // ===========================================
    // Reaction material in manufacturing tree is expanded
    // ===========================================

    public function testReactionMaterialIsExpandedAsBuildable(): void
    {
        // Manufacturing item (200) requires a reaction product (16672)
        // The reaction product should be marked isBuildable with a blueprint sub-tree
        $manufacturingProduct = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);
        $reactionProduct = $this->createProduct(45732, IndustryActivityType::Reaction->value, 16672, 20);

        $this->setupProductLookup([
            200 => ['blueprint' => $manufacturingProduct, 'reaction' => null],
            16672 => ['blueprint' => null, 'reaction' => $reactionProduct],
            16657 => ['blueprint' => null, 'reaction' => null], // raw moon goo
        ]);

        // Materials for item 200: reaction product 16672
        $reactionMat = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 16672, 100);
        // Materials for reaction 45732: moon goo 16657
        $moonGooMat = $this->createMaterial(45732, IndustryActivityType::Reaction->value, 16657, 200);

        $this->activityMaterialRepository
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($reactionMat, $moonGooMat): array {
                return match ($criteria['typeId']) {
                    100 => [$reactionMat],
                    45732 => [$moonGooMat],
                    default => [],
                };
            });

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, match ($typeId) {
                200 => 'T2 Ship',
                16672 => 'Fernite Carbide',
                16657 => 'Fernite',
                default => "Type #{$typeId}",
            }));

        $tree = $this->service->buildProductionTree(200, 1, 10);

        // The reaction material should be buildable with a blueprint sub-tree
        $reactionNode = $tree['materials'][0];
        $this->assertSame(16672, $reactionNode['typeId']);
        $this->assertTrue($reactionNode['isBuildable']);
        $this->assertSame('reaction', $reactionNode['activityType']);
        $this->assertArrayHasKey('blueprint', $reactionNode);

        // The nested reaction blueprint
        $reactionBlueprint = $reactionNode['blueprint'];
        $this->assertSame('reaction', $reactionBlueprint['activityType']);
        $this->assertSame(45732, $reactionBlueprint['blueprintTypeId']);
        $this->assertSame(16672, $reactionBlueprint['productTypeId']);
        $this->assertSame(1, $reactionBlueprint['depth']);

        // Moon goo inside the reaction should be a raw leaf material
        $this->assertCount(1, $reactionBlueprint['materials']);
        $moonGooNode = $reactionBlueprint['materials'][0];
        $this->assertSame(16657, $moonGooNode['typeId']);
        $this->assertFalse($moonGooNode['isBuildable']);
        $this->assertArrayNotHasKey('blueprint', $moonGooNode);
    }

    public function testExcludedReactionIsNotExpanded(): void
    {
        // Same setup as above, but reaction product 16672 is excluded
        $manufacturingProduct = $this->createProduct(100, IndustryActivityType::Manufacturing->value, 200, 1);

        $this->setupProductLookup([
            200 => ['blueprint' => $manufacturingProduct, 'reaction' => null],
            // 16672 won't be looked up because it's excluded
        ]);

        $reactionMat = $this->createMaterial(100, IndustryActivityType::Manufacturing->value, 16672, 100);
        $this->activityMaterialRepository->method('findBy')->willReturn([$reactionMat]);

        $this->invTypeRepository
            ->method('find')
            ->willReturnCallback(fn (int $typeId) => $this->createInvType($typeId, "Type #{$typeId}"));

        // Exclude the reaction product
        $tree = $this->service->buildProductionTree(200, 1, 10, [16672]);

        $reactionNode = $tree['materials'][0];
        $this->assertSame(16672, $reactionNode['typeId']);
        $this->assertFalse($reactionNode['isBuildable']);
        $this->assertArrayNotHasKey('blueprint', $reactionNode);
    }
}
