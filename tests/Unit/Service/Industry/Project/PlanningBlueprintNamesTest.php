<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\Project\PlanningBlueprintNames;
use App\Service\Industry\Project\PlanningLine;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanningBlueprintNames::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-747')]
final class PlanningBlueprintNamesTest extends TestCase
{
    private const int MANUFACTURING_ACTIVITY = 1;
    private const int REACTION_ACTIVITY = 11;
    private const int ISHTAR = 12005;
    private const int ISHTAR_BLUEPRINT = 12006;
    private const int NOCXIUM_ATOMS = 16637;
    private const int NOCXIUM_FORMULA = 46170;
    private const int RAW_SPECIMEN = 99001;
    private const int UNNAMED_PRODUCT = 99002;
    private const int UNNAMED_BLUEPRINT = 99003;

    public function testManufacturingLineIsNamedAfterTheBlueprintThatBuildsItsProduct(): void
    {
        $names = $this->names()->byLineId([$this->line('line-ishtar', self::ISHTAR, 'Ishtar', 'manufacturing')]);

        self::assertSame(['line-ishtar' => 'Ishtar Blueprint'], $names);
    }

    public function testReactionLineIsNamedAfterTheFormulaThatReactsItsProduct(): void
    {
        $names = $this->names()->byLineId([$this->line('line-nocxium', self::NOCXIUM_ATOMS, 'Nocxium Atoms', 'reaction')]);

        self::assertSame(['line-nocxium' => 'Nocxium Atoms Reaction Formula'], $names);
    }

    public function testEachLineKeepsItsOwnBlueprintName(): void
    {
        $names = $this->names()->byLineId([
            $this->line('line-nocxium', self::NOCXIUM_ATOMS, 'Nocxium Atoms', 'reaction'),
            $this->line('line-ishtar', self::ISHTAR, 'Ishtar', 'manufacturing'),
        ]);

        self::assertSame(['line-nocxium' => 'Nocxium Atoms Reaction Formula', 'line-ishtar' => 'Ishtar Blueprint'], $names);
    }

    public function testLineWithoutAnyBlueprintHasNoNameInsteadOfAGuessedOne(): void
    {
        $names = $this->names()->byLineId([$this->line('line-raw', self::RAW_SPECIMEN, 'Raw Specimen', 'manufacturing')]);

        self::assertSame([], $names);
    }

    public function testBlueprintAbsentFromTheSdeTypesHasNoNameInsteadOfAGuessedOne(): void
    {
        $names = $this->names()->byLineId([$this->line('line-unnamed', self::UNNAMED_PRODUCT, 'Unnamed Product', 'manufacturing')]);

        self::assertSame([], $names);
    }

    public function testNoLineNeedsNoLookup(): void
    {
        self::assertSame([], $this->names()->byLineId([]));
    }

    private function names(): PlanningBlueprintNames
    {
        $products = $this->createStub(IndustryActivityProductRepository::class);
        $products->method('findBlueprintForProduct')->willReturnCallback(
            fn (int $productTypeId, int $activityId): ?IndustryActivityProduct => match ([$productTypeId, $activityId]) {
                [self::ISHTAR, self::MANUFACTURING_ACTIVITY] => $this->producer(self::ISHTAR_BLUEPRINT, self::MANUFACTURING_ACTIVITY, self::ISHTAR),
                [self::NOCXIUM_ATOMS, self::REACTION_ACTIVITY] => $this->producer(self::NOCXIUM_FORMULA, self::REACTION_ACTIVITY, self::NOCXIUM_ATOMS),
                [self::UNNAMED_PRODUCT, self::MANUFACTURING_ACTIVITY] => $this->producer(self::UNNAMED_BLUEPRINT, self::MANUFACTURING_ACTIVITY, self::UNNAMED_PRODUCT),
                default => null,
            },
        );

        $types = $this->createStub(InvTypeRepository::class);
        $types->method('findByTypeIds')->willReturnCallback(
            static function (array $typeIds): array {
                $known = [
                    self::ISHTAR_BLUEPRINT => 'Ishtar Blueprint',
                    self::NOCXIUM_FORMULA => 'Nocxium Atoms Reaction Formula',
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

        return new PlanningBlueprintNames($products, $types);
    }

    private function producer(int $blueprintTypeId, int $activityId, int $productTypeId): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity(1);
    }

    private function line(string $lineId, int $typeId, string $typeName, string $activity): PlanningLine
    {
        return new PlanningLine($lineId, $typeId, $typeName, $activity, 1, 0, null, 3600, [], []);
    }
}
