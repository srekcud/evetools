<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\Sde\IndustryActivityProductRepository;
use App\Tests\Fixtures\Sde\ManufacturableProductSde;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-293')]
final class IndustryActivityProductRepositoryTest extends IntegrationTestCase
{
    private IndustryActivityProductRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        ManufacturableProductSde::persist($this->em);
        ManufacturableProductSde::persistUnpublishedInventedProductAndInventedReactionFormula($this->em);
        $this->em->clear();
        $this->repository = self::getContainer()->get(IndustryActivityProductRepository::class);
    }

    public function testProductWhoseManufacturingBlueprintIsInventedIsT2(): void
    {
        self::assertSame(
            [ManufacturableProductSde::HECATE => true],
            $this->t2ProductTypeIdsAmong(ManufacturableProductSde::HECATE),
        );
    }

    public function testProductWithAnInventedAndANonInventedManufacturingBlueprintIsT2(): void
    {
        self::assertSame(
            [ManufacturableProductSde::SVIPUL => true],
            $this->t2ProductTypeIdsAmong(ManufacturableProductSde::SVIPUL),
        );
    }

    public function testUnpublishedProductWhoseManufacturingBlueprintIsInventedIsT2(): void
    {
        self::assertSame(
            [ManufacturableProductSde::UNPUBLISHED_INVENTED_PRODUCT => true],
            $this->t2ProductTypeIdsAmong(ManufacturableProductSde::UNPUBLISHED_INVENTED_PRODUCT),
        );
    }

    #[DataProvider('productsThatAreNotT2')]
    public function testProductThatIsNotT2IsAbsentFromTheT2ProductTypeIds(int $productTypeId): void
    {
        self::assertSame([], $this->t2ProductTypeIdsAmong($productTypeId));
    }

    public static function productsThatAreNotT2(): iterable
    {
        yield 'manufactured product whose blueprint is not invented' => [ManufacturableProductSde::FEROX];
        yield 'unpublished manufactured product whose blueprint is not invented' => [ManufacturableProductSde::UNPUBLISHED_PRODUCT];
        yield 'reaction product' => [ManufacturableProductSde::FERNITE_CARBIDE];
        yield 'reaction product whose reaction formula is invented' => [ManufacturableProductSde::PRODUCT_OF_AN_INVENTED_REACTION_FORMULA];
        yield 'invented blueprint, product of an invention only' => [ManufacturableProductSde::HECATE_BLUEPRINT];
        yield 'invented reaction formula, product of an invention only' => [ManufacturableProductSde::INVENTED_REACTION_FORMULA];
        yield 'type absent from the SDE' => [ManufacturableProductSde::ABSENT_FROM_SDE];
    }

    public function testNoProductTypeIdsHaveNoT2ProductTypeIds(): void
    {
        self::assertSame([], $this->repository->findT2ProductTypeIds([]));
    }

    public function testOneLookupOfMixedProductsKeepsExactlyTheT2OnesKeyedByTheirIntegerTypeIds(): void
    {
        self::assertSame(
            [
                ManufacturableProductSde::HECATE => true,
                ManufacturableProductSde::SVIPUL => true,
                ManufacturableProductSde::UNPUBLISHED_INVENTED_PRODUCT => true,
            ],
            $this->t2ProductTypeIdsAmong(
                ManufacturableProductSde::FEROX,
                ManufacturableProductSde::UNPUBLISHED_INVENTED_PRODUCT,
                ManufacturableProductSde::HECATE_BLUEPRINT,
                ManufacturableProductSde::FERNITE_CARBIDE,
                ManufacturableProductSde::SVIPUL,
                ManufacturableProductSde::ABSENT_FROM_SDE,
                ManufacturableProductSde::PRODUCT_OF_AN_INVENTED_REACTION_FORMULA,
                ManufacturableProductSde::HECATE,
                ManufacturableProductSde::INVENTED_REACTION_FORMULA,
            ),
        );
    }

    private function t2ProductTypeIdsAmong(int ...$productTypeIds): array
    {
        $t2ProductTypeIds = $this->repository->findT2ProductTypeIds($productTypeIds);
        ksort($t2ProductTypeIds);

        return $t2ProductTypeIds;
    }
}
