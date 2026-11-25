<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Planetary;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Planetary\PlanetaryRevenueResource;
use App\Entity\User;
use App\Enum\OrderPositionState;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\Industry\SellOrders\ProjectSellOrder;
use App\Service\Industry\SellOrders\ProjectSellOrders;
use App\Service\Industry\SellOrders\ProjectSellOrdersReader;
use App\Service\Planetary\PlanetaryProductionCalculator;
use App\Service\Planetary\PlanetaryProductTypes;
use App\Service\Planetary\PlanetaryRevenueCalculator;
use App\Service\Sync\WalletReadingStateReader;
use App\State\Provider\Planetary\PlanetaryRevenueProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(PlanetaryRevenueProvider::class)]
#[Group('issue-1068')]
final class PlanetaryRevenueProviderTest extends TestCase
{
    public function testTheDefaultPeriodIsThirtyDays(): void
    {
        $resource = $this->provide(new User(), []);

        $this->assertSame(30, $resource->days);
    }

    public function testTheRequestedPeriodIsUsed(): void
    {
        $resource = $this->provide(new User(), ['days' => '90']);

        $this->assertSame(90, $resource->days);
    }

    public function testAnEmptyAccountGivesNoRealisedRevenueAndAnUnknownChange(): void
    {
        $resource = $this->provide(new User(), []);

        $this->assertSame([0.0, null, [], [], []], [$resource->realisedIsk, $resource->realisedChange, $resource->toFix, $resource->unsyncedWallets, $resource->estimate['contributors']]);
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($resource->tiers, 'tier'));
    }

    #[Group('issue-1401')]
    public function testTheSellOrdersOfThePlanetaryProductsAreExposedWithTheirPositionAndTheCharactersToReconnect(): void
    {
        $user = new User();
        $order = new ProjectSellOrder(5, 'Aria Solberg', 9832, 'Coolant', 4, 60_003_760, 'Jita IV - Moon 4', 1000.0, 900.0, 2, OrderPositionState::Undercut, null, new \DateTimeImmutable('2026-11-10 12:10:00'), 800.0);
        $reader = $this->createMock(ProjectSellOrdersReader::class);
        $reader->expects(self::once())
            ->method('forProducts')
            ->with($user, [9832 => 'Coolant'])
            ->willReturn(new ProjectSellOrders([$order], ['Brann Holt'], new \DateTimeImmutable('2026-11-10 12:15:00')));

        $resource = $this->provide($user, [], [9832 => 'Coolant'], $reader);

        $this->assertSame(
            ['orders' => [$order->toArray()], 'charactersToReconnect' => ['Brann Holt'], 'syncedAt' => '2026-11-10T12:15:00+00:00'],
            $resource->sellOrders,
        );
    }

    #[Group('issue-1401')]
    public function testWithoutPlanetaryProductThereIsNoSellOrderAndNoRead(): void
    {
        $reader = $this->createMock(ProjectSellOrdersReader::class);
        $reader->expects(self::never())->method('forProducts');

        $resource = $this->provide(new User(), [], [], $reader);

        $this->assertSame(['orders' => [], 'charactersToReconnect' => [], 'syncedAt' => null], $resource->sellOrders);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDays(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'above one year' => ['366'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['1.5'];
    }

    #[DataProvider('invalidDays')]
    public function testAnInvalidPeriodIsRejected(string $days): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->provide(new User(), ['days' => $days]);
    }

    public function testAnAnonymousVisitorIsRejected(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(null, []);
    }

    /**
     * @param array<string, string>  $query
     * @param array<int, string>     $planetaryProducts
     */
    private function provide(?User $user, array $query, array $planetaryProducts = [], ?ProjectSellOrdersReader $sellOrdersReader = null): PlanetaryRevenueResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        $colonyRepository = $this->createStub(PlanetaryColonyRepository::class);
        $colonyRepository->method('findByUser')->willReturn([]);
        $schematicTypeRepository = $this->createStub(PlanetSchematicTypeRepository::class);
        $schematicTypeRepository->method('getSchematicMap')->willReturn([]);
        $productionCalculator = $this->createStub(PlanetaryProductionCalculator::class);
        $productionCalculator->method('calculateTotalDailyIsk')->willReturn(0.0);

        $calculator = new PlanetaryRevenueCalculator(
            $this->createStub(CachedWalletTransactionRepository::class),
            $colonyRepository,
            $schematicTypeRepository,
            $productionCalculator,
            new WalletReadingStateReader($this->createStub(CharacterWalletReadingRepository::class)),
        );

        $productTypes = $this->createStub(PlanetaryProductTypes::class);
        $productTypes->method('of')->willReturn($planetaryProducts);

        return (new PlanetaryRevenueProvider($security, $requestStack, $calculator, $productTypes, $sellOrdersReader ?? $this->createStub(ProjectSellOrdersReader::class)))->provide(new Get());
    }
}
