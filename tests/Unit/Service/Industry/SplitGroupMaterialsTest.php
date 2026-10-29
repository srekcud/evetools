<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\SplitGroupMaterials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SplitGroupMaterials::class)]
final class SplitGroupMaterialsTest extends TestCase
{
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46166;
    private const int FERNITE_CARBIDE_UNITS_PER_RUN = 10_000;
    private const int PRODUCT_MANUFACTURED_AND_REACTED = 990_457;
    private const int BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED = 991_457;
    private const int FORMULA_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED = 992_457;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int HELIUM_FUEL_BLOCK_BLUEPRINT = 1_004_247;
    private const int HELIUM_FUEL_BLOCK_UNITS_PER_RUN = 40;
    private const int BLUEPRINT_MISSING_FROM_THE_SDE = 999_999_999;

    #[Group('issue-137')]
    public function testUnitsPerRunOfAProductAreItsQuantityInTheSde(): void
    {
        $reactionProduct = $this->activityProduct(self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_UNITS_PER_RUN);

        self::assertSame(10_000, SplitGroupMaterials::outputPerRunOfProduct($reactionProduct, self::FERNITE_CARBIDE_REACTION_FORMULA));
    }

    #[Group('issue-137')]
    public function testProductMissingFromTheSdeHasNoUnitsPerRunAndNamesItsBlueprint(): void
    {
        $this->expectException(BlueprintProductMissingFromSdeException::class);
        $this->expectExceptionMessage('Product of blueprint 999999999 missing from the SDE');

        SplitGroupMaterials::outputPerRunOfProduct(null, self::BLUEPRINT_MISSING_FROM_THE_SDE);
    }

    #[Group('issue-279')]
    public function testUnitsPerRunOfACopyStepAreOneBlueprintCopy(): void
    {
        $repository = $this->productRepositoryWith(
            $this->activityProduct(self::HELIUM_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::HELIUM_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK_UNITS_PER_RUN),
        );

        self::assertSame(1, SplitGroupMaterials::outputPerRunOf($repository, self::HELIUM_FUEL_BLOCK_BLUEPRINT, 'copy'));
    }

    #[Group('issue-279')]
    public function testUnitsPerRunOfACopyStepWhoseBlueprintIsMissingFromTheSdeAreOneBlueprintCopyWithoutReadingTheSde(): void
    {
        $repository = $this->createMock(IndustryActivityProductRepository::class);
        $repository->expects($this->never())->method('findOneBy');

        self::assertSame(1, SplitGroupMaterials::outputPerRunOf($repository, self::BLUEPRINT_MISSING_FROM_THE_SDE, 'copy'));
    }

    #[Group('issue-279')]
    public function testUnitsPerRunOfAManufacturingStepAreTheUnitsPerRunOfTheProductOfItsBlueprint(): void
    {
        $repository = $this->productRepositoryWith(
            $this->activityProduct(self::HELIUM_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::HELIUM_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK_UNITS_PER_RUN),
        );

        self::assertSame(40, SplitGroupMaterials::outputPerRunOf($repository, self::HELIUM_FUEL_BLOCK_BLUEPRINT, 'manufacturing'));
    }

    #[Group('issue-137')]
    public function testUnitsPerRunOfAStepWhoseBlueprintHasNoProductForItsActivityAreRefused(): void
    {
        $repository = $this->productRepositoryWith(
            $this->activityProduct(self::HELIUM_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::HELIUM_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK_UNITS_PER_RUN),
        );

        $this->expectException(BlueprintProductMissingFromSdeException::class);
        $this->expectExceptionMessage('Product of blueprint 1004247 missing from the SDE');

        SplitGroupMaterials::outputPerRunOf($repository, self::HELIUM_FUEL_BLOCK_BLUEPRINT, 'reaction');
    }

    #[Group('issue-125')]
    public function testProducerOfAProductWithoutBlueprintIsItsReactionFormula(): void
    {
        $reactionFormula = $this->activityProduct(self::FERNITE_CARBIDE_REACTION_FORMULA, IndustryActivityType::Reaction, self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_UNITS_PER_RUN);

        self::assertSame($reactionFormula, SplitGroupMaterials::producerOf($this->productRepositoryWith($reactionFormula), self::FERNITE_CARBIDE));
    }

    #[Group('issue-125')]
    public function testProducerOfAProductManufacturedAndReactedIsItsBlueprint(): void
    {
        $blueprint = $this->activityProduct(self::BLUEPRINT_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, IndustryActivityType::Manufacturing, self::PRODUCT_MANUFACTURED_AND_REACTED, 40);
        $reactionFormula = $this->activityProduct(self::FORMULA_OF_THE_PRODUCT_MANUFACTURED_AND_REACTED, IndustryActivityType::Reaction, self::PRODUCT_MANUFACTURED_AND_REACTED, 200);

        self::assertSame($blueprint, SplitGroupMaterials::producerOf($this->productRepositoryWith($reactionFormula, $blueprint), self::PRODUCT_MANUFACTURED_AND_REACTED));
    }

    #[Group('issue-125')]
    public function testProductWithoutBlueprintNorReactionFormulaHasNoProducer(): void
    {
        $repository = $this->productRepositoryWith(
            $this->activityProduct(self::HELIUM_FUEL_BLOCK_BLUEPRINT, IndustryActivityType::Manufacturing, self::HELIUM_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK_UNITS_PER_RUN),
        );

        $this->expectException(ProducerMissingFromSdeException::class);
        $this->expectExceptionMessage('No blueprint or reaction found for product type ID 16673');

        SplitGroupMaterials::producerOf($repository, self::FERNITE_CARBIDE);
    }

    private function productRepositoryWith(IndustryActivityProduct ...$products): IndustryActivityProductRepository
    {
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($products): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($product->getTypeId() === $criteria['typeId'] && $product->getActivityId() === $criteria['activityId']) {
                        return $product;
                    }
                }

                return null;
            },
        );
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            static function (int $productTypeId, int $activityId = 1) use ($products): ?IndustryActivityProduct {
                foreach ($products as $product) {
                    if ($product->getProductTypeId() === $productTypeId && $product->getActivityId() === $activityId) {
                        return $product;
                    }
                }

                return null;
            },
        );

        return $repository;
    }

    private function activityProduct(int $blueprintTypeId, IndustryActivityType $activity, int $productTypeId, int $unitsPerRun): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activity->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity($unitsPerRun);
    }
}
