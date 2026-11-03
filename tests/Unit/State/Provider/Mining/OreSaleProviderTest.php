<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Mining\OreSaleResource;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Service\Mining\OreSaleService;
use App\State\Provider\Mining\OreSaleProvider;
use App\Tests\Unit\Service\Mining\BuildsOreSaleServiceOnHeldOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(OreSaleProvider::class)]
#[Group('issue-314')]
final class OreSaleProviderTest extends TestCase
{
    use BuildsOreSaleServiceOnHeldOres;

    private const float VALUE_DELTA = 1e-9;

    public function testWithoutStructureTheSaleOfTheConnectedUserIsValuedOnJita(): void
    {
        $sale = $this->provideFor([]);

        $this->assertNull($sale->structureId);
        $this->assertSame([self::COMPRESSED_CLEAR_ICICLE, self::COMPRESSED_VELDSPAR], array_column($sale->rows, 'typeId'));
        $this->assertSame([null, null], array_column($sale->rows, 'structureBuyPrice'));
        $this->assertSame(['jita_sell', 'jita_sell'], array_column($sale->rows, 'bestSource'));
        $this->assertSame(20_005, $sale->totals['totalQuantity']);
        $this->assertEqualsWithDelta(520.0, $sale->totals['totalVolume'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(1_280_000.0, $sale->totals['totalValue'], self::VALUE_DELTA);
    }

    public function testTheStructureIdOfTheQueryAddsTheStructurePrices(): void
    {
        $sale = $this->provideFor(['structureId' => (string) self::SALE_STRUCTURE_ID]);

        $this->assertSame(self::SALE_STRUCTURE_ID, $sale->structureId);
        $this->assertSame([self::COMPRESSED_CLEAR_ICICLE, self::COMPRESSED_VELDSPAR], array_column($sale->rows, 'typeId'));
        $this->assertSame(['structure_sell', 'structure_buy'], array_column($sale->rows, 'bestSource'));
        $this->assertSame([182_000.0, 21.0], array_column($sale->rows, 'bestPrice'));
        $this->assertEqualsWithDelta(1_330_000.0, $sale->totals['totalValue'], self::VALUE_DELTA);
    }

    #[DataProvider('invalidStructureIds')]
    public function testAStructureIdThatIsNotAPositiveIntegerIsABadRequest(string $structureId): void
    {
        $user = new User();
        $cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $cachedAssetRepository->expects($this->never())->method('getAggregatedQuantitiesByUser');

        $this->expectException(BadRequestHttpException::class);

        $this->providerFor($user, new Request(['structureId' => $structureId]), $this->oreSaleServiceReading($cachedAssetRepository))
            ->provide(new Get());
    }

    public static function invalidStructureIds(): iterable
    {
        yield 'not a number' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1022734985679'];
        yield 'decimal' => ['1.5'];
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $cachedAssetRepository->expects($this->never())->method('getAggregatedQuantitiesByUser');

        $this->expectException(UnauthorizedHttpException::class);

        (new OreSaleProvider($security, $requestStack, $this->oreSaleServiceReading($cachedAssetRepository)))->provide(new Get());
    }

    public function testAProvideCallOutsideAnHttpRequestIsABadRequest(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $this->expectException(BadRequestHttpException::class);

        (new OreSaleProvider($security, new RequestStack(), $this->oreSaleServiceReading($this->createStub(CachedAssetRepository::class))))
            ->provide(new Get());
    }

    private function provideFor(array $query): OreSaleResource
    {
        $user = new User();
        $oreSaleService = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 20_000, self::COMPRESSED_CLEAR_ICICLE => 5],
            [self::COMPRESSED_VELDSPAR => 17.0, self::COMPRESSED_CLEAR_ICICLE => 175_000.0],
            [self::COMPRESSED_VELDSPAR => 19.0, self::COMPRESSED_CLEAR_ICICLE => 180_000.0],
            [self::COMPRESSED_VELDSPAR => 21.0, self::COMPRESSED_CLEAR_ICICLE => 176_500.0],
            [self::COMPRESSED_VELDSPAR => 20.5, self::COMPRESSED_CLEAR_ICICLE => 182_000.0],
        );

        return $this->providerFor($user, new Request($query), $oreSaleService)->provide(new Get());
    }

    private function providerFor(User $user, Request $request, OreSaleService $oreSaleService): OreSaleProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new OreSaleProvider($security, $requestStack, $oreSaleService);
    }

    private function oreSaleServiceReading(CachedAssetRepository $cachedAssetRepository): OreSaleService
    {
        return new OreSaleService(
            $cachedAssetRepository,
            $this->compressedMineableTypeRepository([]),
            $this->jitaMarketWithFallback([], []),
            $this->saleStructureMarket([], []),
        );
    }
}
