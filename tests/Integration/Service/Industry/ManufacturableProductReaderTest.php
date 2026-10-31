<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry;

use App\Service\Industry\ManufacturableProduct;
use App\Service\Industry\ManufacturableProductReader;
use App\Tests\Fixtures\Sde\ManufacturableProductSde;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-238')]
final class ManufacturableProductReaderTest extends IntegrationTestCase
{
    private const int SEARCH_LIMIT = 20;

    private ManufacturableProductReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        ManufacturableProductSde::persist($this->em);
        $this->em->clear();
        $this->reader = self::getContainer()->get(ManufacturableProductReader::class);
    }

    public function testManufacturedProductWhoseBlueprintIsInventedIsT2(): void
    {
        self::assertEquals(
            new ManufacturableProduct(typeId: ManufacturableProductSde::HECATE, typeName: ManufacturableProductSde::HECATE_NAME, isT2: true),
            $this->reader->findByTypeId(ManufacturableProductSde::HECATE),
        );
    }

    public function testManufacturedProductWhoseBlueprintIsNotInventedIsNotT2(): void
    {
        self::assertEquals(
            new ManufacturableProduct(typeId: ManufacturableProductSde::FEROX, typeName: ManufacturableProductSde::FEROX_NAME, isT2: false),
            $this->reader->findByTypeId(ManufacturableProductSde::FEROX),
        );
    }

    public function testReactionProductIsNotT2(): void
    {
        self::assertEquals(
            new ManufacturableProduct(typeId: ManufacturableProductSde::FERNITE_CARBIDE, typeName: ManufacturableProductSde::FERNITE_CARBIDE_NAME, isT2: false),
            $this->reader->findByTypeId(ManufacturableProductSde::FERNITE_CARBIDE),
        );
    }

    #[DataProvider('typesThatAreNotManufacturableProducts')]
    public function testTypeThatIsNotAManufacturableProductIsNotFoundByIdentifier(int $typeId): void
    {
        self::assertNull($this->reader->findByTypeId($typeId));
    }

    public static function typesThatAreNotManufacturableProducts(): iterable
    {
        yield 'invented blueprint, product of an invention only' => [ManufacturableProductSde::HECATE_BLUEPRINT];
        yield 'blueprint product of no activity' => [ManufacturableProductSde::FEROX_BLUEPRINT];
        yield 'reaction formula' => [ManufacturableProductSde::FERNITE_CARBIDE_REACTION_FORMULA];
        yield 'type absent from the SDE' => [ManufacturableProductSde::ABSENT_FROM_SDE];
        yield 'unpublished manufactured product' => [ManufacturableProductSde::UNPUBLISHED_PRODUCT];
    }

    #[DataProvider('searchedProducts')]
    public function testSearchByNameAndLookupByIdentifierGiveTheSameProduct(string $searchedName, int $typeId): void
    {
        self::assertEquals(
            [$this->reader->findByTypeId($typeId)],
            $this->fixtureProductsFound($searchedName),
        );
    }

    public static function searchedProducts(): iterable
    {
        yield 'T1 ship' => [ManufacturableProductSde::FEROX_NAME, ManufacturableProductSde::FEROX];
        yield 'T2 ship' => [ManufacturableProductSde::HECATE_NAME, ManufacturableProductSde::HECATE];
        yield 'reaction product' => [ManufacturableProductSde::FERNITE_CARBIDE_NAME, ManufacturableProductSde::FERNITE_CARBIDE];
    }

    public function testSearchFindsNeitherBlueprintsNorUnpublishedProducts(): void
    {
        self::assertEquals(
            [new ManufacturableProduct(typeId: ManufacturableProductSde::HECATE, typeName: ManufacturableProductSde::HECATE_NAME, isT2: true)],
            $this->fixtureProductsFound(ManufacturableProductSde::HECATE_NAME),
        );
    }

    public function testProductWithAnInventedAndANonInventedBlueprintIsOneT2ProductByIdentifier(): void
    {
        self::assertEquals(
            new ManufacturableProduct(typeId: ManufacturableProductSde::SVIPUL, typeName: ManufacturableProductSde::SVIPUL_NAME, isT2: true),
            $this->reader->findByTypeId(ManufacturableProductSde::SVIPUL),
        );
    }

    private function fixtureProductsFound(string $searchedName): array
    {
        return array_values(array_filter(
            $this->reader->search($searchedName, self::SEARCH_LIMIT),
            static fn (ManufacturableProduct $product): bool => in_array($product->typeId, ManufacturableProductSde::FIXTURE_TYPE_IDS, true),
        ));
    }
}
