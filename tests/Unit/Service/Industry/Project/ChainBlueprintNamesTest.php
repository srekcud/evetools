<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Project\ChainBlueprintNames;
use App\Service\Industry\Project\PlanningBlueprintNames;
use App\Service\Industry\Project\ProjectChainNode;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainBlueprintNames::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-906')]
final class ChainBlueprintNamesTest extends TestCase
{
    private const int MANUFACTURING_ACTIVITY = 1;
    private const int REACTION_ACTIVITY = 11;
    private const int ISHTAR = 12005;
    private const int ISHTAR_BLUEPRINT = 12006;
    private const int NOCXIUM_ATOMS = 16637;
    private const int NOCXIUM_FORMULA = 46170;
    private const int RAW_SPECIMEN = 99001;
    private const int HAMMERHEAD_II = 2205;
    private const int HAMMERHEAD_II_BLUEPRINT = 2206;

    public function testManufacturingJobIsNamedAfterTheBlueprintThatBuildsItsProduct(): void
    {
        $names = $this->names()->of([$this->node('node-ishtar', 'component', 'manufacturing', self::ISHTAR)]);

        self::assertSame(['node-ishtar' => 'Ishtar Blueprint'], $names);
    }

    public function testReactionJobIsNamedAfterTheFormula(): void
    {
        $names = $this->names()->of([$this->node('node-nocxium', 'component', 'reaction', self::NOCXIUM_ATOMS)]);

        self::assertSame(['node-nocxium' => 'Nocxium Atoms Reaction Formula'], $names);
    }

    public function testCopyJobIsNamedAfterTheBlueprintItCopies(): void
    {
        $names = $this->names()->of([$this->node('node-ishtar-copy', 'blueprint', 'copy', self::ISHTAR)]);

        self::assertSame(['node-ishtar-copy' => 'Ishtar Blueprint'], $names);
    }

    public function testInventionJobHasNoNameInsteadOfTheBlueprintOfItsInventedProduct(): void
    {
        $names = $this->names()->of([$this->node('node-hammerhead-invention', 'blueprint', 'invention', self::HAMMERHEAD_II)]);

        self::assertSame([], $names);
    }

    public function testMaterialAndSaleNodesHaveNoName(): void
    {
        $names = $this->names()->of([
            $this->node('node-ishtar-material', 'material', null, self::ISHTAR),
            $this->node('sale', 'sale', null, null),
        ]);

        self::assertSame([], $names);
    }

    public function testJobWithoutAnyBlueprintHasNoNameInsteadOfAGuessedOne(): void
    {
        $names = $this->names()->of([$this->node('node-raw', 'component', 'manufacturing', self::RAW_SPECIMEN)]);

        self::assertSame([], $names);
    }

    public function testEachNodeKeepsItsOwnName(): void
    {
        $names = $this->names()->of([
            $this->node('node-nocxium', 'component', 'reaction', self::NOCXIUM_ATOMS),
            $this->node('node-ishtar', 'product', 'manufacturing', self::ISHTAR),
            $this->node('node-ishtar-copy', 'blueprint', 'copy', self::ISHTAR),
            $this->node('node-raw', 'component', 'manufacturing', self::RAW_SPECIMEN),
        ]);

        self::assertSame(
            ['node-nocxium' => 'Nocxium Atoms Reaction Formula', 'node-ishtar' => 'Ishtar Blueprint', 'node-ishtar-copy' => 'Ishtar Blueprint'],
            $names,
        );
    }

    public function testNoNodeNeedsNoLookup(): void
    {
        self::assertSame([], $this->names()->of([]));
    }

    private function names(): ChainBlueprintNames
    {
        $products = $this->createStub(IndustryActivityProductRepository::class);
        $products->method('findBlueprintForProduct')->willReturnCallback(
            fn (int $productTypeId, int $activityId): ?IndustryActivityProduct => match ([$productTypeId, $activityId]) {
                [self::ISHTAR, self::MANUFACTURING_ACTIVITY] => $this->producer(self::ISHTAR_BLUEPRINT, self::MANUFACTURING_ACTIVITY, self::ISHTAR),
                [self::NOCXIUM_ATOMS, self::REACTION_ACTIVITY] => $this->producer(self::NOCXIUM_FORMULA, self::REACTION_ACTIVITY, self::NOCXIUM_ATOMS),
                [self::HAMMERHEAD_II, self::MANUFACTURING_ACTIVITY] => $this->producer(self::HAMMERHEAD_II_BLUEPRINT, self::MANUFACTURING_ACTIVITY, self::HAMMERHEAD_II),
                default => null,
            },
        );

        $types = $this->createStub(InvTypeRepository::class);
        $types->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds): array {
                $known = [
                    self::ISHTAR_BLUEPRINT => 'Ishtar Blueprint',
                    self::NOCXIUM_FORMULA => 'Nocxium Atoms Reaction Formula',
                    self::HAMMERHEAD_II_BLUEPRINT => 'Hammerhead II Blueprint',
                ];
                $found = [];
                foreach ($typeIds as $typeId) {
                    if (isset($known[$typeId])) {
                        $found[$typeId] = (new InvType())->setTypeId($typeId)->setTypeName($known[$typeId]);
                    }
                }

                return $found;
            },
        );

        return new ChainBlueprintNames(new PlanningBlueprintNames($products, $types));
    }

    private function producer(int $blueprintTypeId, int $activityId, int $productTypeId): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity(1);
    }

    private function node(string $id, string $stage, ?string $activity, ?int $typeId): ProjectChainNode
    {
        return new ProjectChainNode($id, $stage, null, $typeId, null, $activity, null, null, null, null, null, 'todo', 'none', null, [], [], [], null, null, null, null);
    }
}
