<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ManufacturableTypeResource;
use App\Service\Industry\ManufacturableProduct;
use App\Service\Industry\ManufacturableProductReader;
use App\State\Provider\Industry\ManufacturableTypeProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('issue-238')]
final class ManufacturableTypeProviderTest extends TestCase
{
    private const int FEROX_TYPE_ID = 990_001;
    private const int HECATE_TYPE_ID = 990_002;
    private const int ABSENT_TYPE_ID = 990_099;

    #[DataProvider('invalidTypeIds')]
    public function testInvalidTypeIdIsNotFoundWithoutReadingTheSde(int|string $typeId): void
    {
        $reader = $this->createMock(ManufacturableProductReader::class);
        $reader->expects(self::never())->method('findByTypeId');

        $this->expectException(NotFoundHttpException::class);

        (new ManufacturableTypeProvider($reader))->provide(new Get(), ['typeId' => $typeId]);
    }

    public static function invalidTypeIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'letters' => ['abc'];
        yield 'decimal' => ['1.5'];
        yield 'leading zeros' => ['007'];
        yield 'beyond a PHP integer' => ['99999999999999999999'];
        yield 'signed' => ['+42'];
        yield 'empty' => [''];
        yield 'zero as integer' => [0];
        yield 'negative as integer' => [-5];
    }

    public function testTypeThatIsNotAManufacturableProductIsNotFound(): void
    {
        $reader = $this->createMock(ManufacturableProductReader::class);
        $reader->expects(self::once())->method('findByTypeId')->with(self::ABSENT_TYPE_ID)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        (new ManufacturableTypeProvider($reader))->provide(new Get(), ['typeId' => (string) self::ABSENT_TYPE_ID]);
    }

    public function testInvalidAndUnknownTypeIdsAreNotFoundWithTheSameMessage(): void
    {
        $reader = $this->createStub(ManufacturableProductReader::class);
        $reader->method('findByTypeId')->willReturn(null);
        $provider = new ManufacturableTypeProvider($reader);

        self::assertSame(
            $this->notFoundMessage($provider, (string) self::ABSENT_TYPE_ID),
            $this->notFoundMessage($provider, '007'),
        );
    }

    #[DataProvider('manufacturableProducts')]
    public function testManufacturableProductIsReturnedWithItsNameAndT2Flag(int|string $requestedTypeId, ManufacturableProduct $product): void
    {
        $reader = $this->createMock(ManufacturableProductReader::class);
        $reader->expects(self::once())->method('findByTypeId')->with($product->typeId)->willReturn($product);

        $resource = (new ManufacturableTypeProvider($reader))->provide(new Get(), ['typeId' => $requestedTypeId]);

        self::assertInstanceOf(ManufacturableTypeResource::class, $resource);
        self::assertSame(
            ['typeId' => $product->typeId, 'typeName' => $product->typeName, 'isT2' => $product->isT2],
            ['typeId' => $resource->typeId, 'typeName' => $resource->typeName, 'isT2' => $resource->isT2],
        );
    }

    public static function manufacturableProducts(): iterable
    {
        yield 'T1 ship' => [(string) self::FEROX_TYPE_ID, new ManufacturableProduct(typeId: self::FEROX_TYPE_ID, typeName: 'Ferox', isT2: false)];
        yield 'T2 ship' => [(string) self::HECATE_TYPE_ID, new ManufacturableProduct(typeId: self::HECATE_TYPE_ID, typeName: 'Hecate', isT2: true)];
        yield 'T2 ship requested as integer' => [self::HECATE_TYPE_ID, new ManufacturableProduct(typeId: self::HECATE_TYPE_ID, typeName: 'Hecate', isT2: true)];
    }

    private function notFoundMessage(ManufacturableTypeProvider $provider, string $typeId): string
    {
        try {
            $provider->provide(new Get(), ['typeId' => $typeId]);
        } catch (NotFoundHttpException $notFound) {
            return $notFound->getMessage();
        }

        self::fail(sprintf('Type id "%s" was found', $typeId));
    }
}
