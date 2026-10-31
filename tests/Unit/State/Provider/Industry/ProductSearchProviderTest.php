<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ProductSearchListResource;
use App\ApiResource\Industry\ProductSearchResource;
use App\Service\Industry\ManufacturableProduct;
use App\Service\Industry\ManufacturableProductReader;
use App\State\Provider\Industry\ProductSearchProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[Group('issue-238')]
final class ProductSearchProviderTest extends TestCase
{
    private const int SEARCH_LIMIT = 20;

    #[DataProvider('queriesShorterThanTwoCharacters')]
    public function testQueryShorterThanTwoCharactersFindsNothingWithoutReadingTheSde(?string $query): void
    {
        $reader = $this->createMock(ManufacturableProductReader::class);
        $reader->expects(self::never())->method('search');

        $results = $this->search($reader, $query);

        self::assertSame([], $results->results);
    }

    public static function queriesShorterThanTwoCharacters(): iterable
    {
        yield 'no query' => [null];
        yield 'empty query' => [''];
        yield 'one character' => ['H'];
    }

    public function testProductsReadFromTheSdeWithALimitOfTwentyAreReturnedInTheirOrder(): void
    {
        $reader = $this->createMock(ManufacturableProductReader::class);
        $reader->expects(self::once())->method('search')->with('He', self::SEARCH_LIMIT)->willReturn([
            new ManufacturableProduct(typeId: 990_002, typeName: 'Hecate', isT2: true),
            new ManufacturableProduct(typeId: 990_007, typeName: 'Heron', isT2: false),
            new ManufacturableProduct(typeId: 990_008, typeName: 'Helios', isT2: true),
        ]);

        $results = $this->search($reader, 'He');

        self::assertContainsOnlyInstancesOf(ProductSearchResource::class, $results->results);
        self::assertSame(
            [
                ['typeId' => 990_002, 'typeName' => 'Hecate', 'isT2' => true],
                ['typeId' => 990_007, 'typeName' => 'Heron', 'isT2' => false],
                ['typeId' => 990_008, 'typeName' => 'Helios', 'isT2' => true],
            ],
            array_map(
                static fn (ProductSearchResource $row): array => ['typeId' => $row->typeId, 'typeName' => $row->typeName, 'isT2' => $row->isT2],
                $results->results,
            ),
        );
    }

    private function search(ManufacturableProductReader $reader, ?string $query): ProductSearchListResource
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(null === $query ? [] : ['q' => $query]));

        return (new ProductSearchProvider($reader, $requestStack))->provide(new Get());
    }
}
