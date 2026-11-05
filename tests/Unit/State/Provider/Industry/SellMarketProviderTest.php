<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\SellMarketResource;
use App\Entity\User;
use App\Service\Industry\SellMarkets\ImmediateSale;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\SellMarketProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[Group('issue-348')]
#[CoversClass(SellMarketProvider::class)]
#[CoversClass(SellMarketResource::class)]
final class SellMarketProviderTest extends TestCase
{
    private const int HAIL_L_TYPE_ID = 12779;
    private const string HAIL_L_NAME = 'Hail L';

    #[DataProvider('invalidQuantities')]
    public function testQuantityThatIsMissingNotAnIntegerOrOutOfBoundsIsABadRequest(?string $quantity): void
    {
        $comparison = $this->createMock(SellMarketComparison::class);
        $comparison->expects($this->never())->method('compare');

        $this->expectException(BadRequestHttpException::class);

        $this->provider(new User(), $quantity, $comparison)->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public static function invalidQuantities(): iterable
    {
        yield 'absent' => [null];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'one above the maximum' => ['100000001'];
        yield 'decimal' => ['2.5'];
        yield 'text' => ['fifty'];
    }

    #[DataProvider('invalidTypeIds')]
    public function testTypeIdThatIsNotAPositiveIntegerOfThePlatformIsABadRequest(string $typeId): void
    {
        $comparison = $this->createMock(SellMarketComparison::class);
        $comparison->expects($this->never())->method('compare');

        $this->expectException(BadRequestHttpException::class);

        $this->provider(new User(), '50', $comparison)->provide(new Get(), ['typeId' => $typeId]);
    }

    public static function invalidTypeIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-12779'];
        yield 'text' => ['hail'];
        yield 'one above the integer maximum' => ['2147483648'];
    }

    #[DataProvider('acceptedBoundaries')]
    public function testSmallestAndLargestTypeIdsAndQuantityOneAreCompared(string|int $typeId, int $expectedTypeId): void
    {
        $comparison = $this->createMock(SellMarketComparison::class);
        $comparison->expects($this->once())
            ->method('compare')
            ->with($this->anything(), $expectedTypeId, 1, $this->anything())
            ->willReturn($this->resultWithoutPrices(1));

        $resource = $this->provider(new User(), '1', $comparison)->provide(new Get(), ['typeId' => $typeId]);

        $this->assertSame(1, $resource->quantity);
    }

    public static function acceptedBoundaries(): iterable
    {
        yield 'smallest type id' => ['1', 1];
        yield 'largest type id' => ['2147483647', 2_147_483_647];
        yield 'integer type id' => [12779, 12779];
    }

    public function testMaximumQuantityIsAcceptedAndComparedForTheAuthenticatedUserToday(): void
    {
        $user = new User();
        $comparison = $this->createMock(SellMarketComparison::class);
        $comparison->expects($this->once())
            ->method('compare')
            ->with(
                $user,
                self::HAIL_L_TYPE_ID,
                100_000_000,
                $this->callback(static fn (\DateTimeImmutable $today): bool => $today->format('Y-m-d') === '2026-11-05'),
            )
            ->willReturn($this->resultWithoutPrices(100_000_000));

        $resource = $this->provider($user, '100000000', $comparison)->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(100_000_000, $resource->quantity);
    }

    public function testWithoutAuthenticatedUserTheRequestIsUnauthorized(): void
    {
        $comparison = $this->createMock(SellMarketComparison::class);
        $comparison->expects($this->never())->method('compare');

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider(null, '50', $comparison)->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public function testResultIsMappedWithCoverageAsRoundedPercentAndUnknownValuesKeptNull(): void
    {
        $comparison = $this->createStub(SellMarketComparison::class);
        $comparison->method('compare')->willReturn(new SellMarketComparisonResult(
            self::HAIL_L_TYPE_ID,
            50,
            [
                new SellMarketRow('jita', SellMarketComparison::JITA_MARKET_NAME, 752500.0, 0.33333, 20.0, 'region_history', 0.04755, 716719.9, 25.0),
                new SellMarketRow('capital', 'Perimeter - Tranquility Trade Hub', 800000.0, null, null, 'structure_estimate', 0.04755, 761960.0, null),
            ],
            new ImmediateSale(705000.0, 0.5, 681206.25),
        ));

        $resource = $this->provider(new User(), '50', $comparison)->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(self::HAIL_L_TYPE_ID, $resource->typeId);
        $this->assertSame(self::HAIL_L_NAME, $resource->typeName);
        $this->assertSame(50, $resource->quantity);
        $this->assertSame(0.1, $resource->sellShareOfDailyVolume);
        $this->assertSame([
            [
                'key' => 'jita',
                'name' => 'Jita IV - Moon 4',
                'unitPrice' => 752500.0,
                'coveragePercent' => 33.3,
                'dailyVolume' => 20.0,
                'volumeKind' => 'region_history',
                'feeRate' => 0.04755,
                'netUnitPrice' => 716719.9,
                'daysToSell' => 25.0,
            ],
            [
                'key' => 'capital',
                'name' => 'Perimeter - Tranquility Trade Hub',
                'unitPrice' => 800000.0,
                'coveragePercent' => null,
                'dailyVolume' => null,
                'volumeKind' => 'structure_estimate',
                'feeRate' => 0.04755,
                'netUnitPrice' => 761960.0,
                'daysToSell' => null,
            ],
        ], $resource->markets);
        $this->assertSame(['averageUnitPrice' => 705000.0, 'coveragePercent' => 50.0, 'netUnitPrice' => 681206.25], $resource->immediateSale);
    }

    public function testUnknownImmediateSaleAndUnknownTypeNameStayNull(): void
    {
        $comparison = $this->createStub(SellMarketComparison::class);
        $comparison->method('compare')->willReturn($this->resultWithoutPrices(50));

        $resource = $this->provider(new User(), '50', $comparison, typeName: null)->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertNull($resource->typeName);
        $this->assertSame(['averageUnitPrice' => null, 'coveragePercent' => null, 'netUnitPrice' => null], $resource->immediateSale);
        $this->assertSame(0.1, $resource->sellShareOfDailyVolume);
    }

    private function resultWithoutPrices(int $quantity): SellMarketComparisonResult
    {
        return new SellMarketComparisonResult(self::HAIL_L_TYPE_ID, $quantity, [], new ImmediateSale(null, null, null));
    }

    private function provider(?User $user, ?string $quantity, SellMarketComparison $comparison, ?string $typeName = self::HAIL_L_NAME): SellMarketProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        $requestStack->push(new Request($quantity === null ? [] : ['quantity' => $quantity]));

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findName')->willReturnCallback(
            static fn (int $typeId): ?string => $typeId === self::HAIL_L_TYPE_ID ? $typeName : null,
        );

        return new SellMarketProvider($security, $requestStack, $comparison, $typeNameResolver, new MockClock('2026-11-05 10:30:00 UTC'));
    }
}
