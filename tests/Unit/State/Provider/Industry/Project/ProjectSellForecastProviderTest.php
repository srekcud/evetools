<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\Project\ProjectSellForecastResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectSale;
use App\Entity\User;
use App\Service\Industry\Project\ProjectSellForecast;
use App\Service\Industry\Project\RemainingBomCost;
use App\Service\Industry\Project\SellStructure;
use App\Service\Industry\Project\UnsoldProductsOfProject;
use App\Service\Industry\SellMarkets\ImmediateSale;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectSellForecastProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectSellForecastProvider::class)]
#[Group('issue-50')]
final class ProjectSellForecastProviderTest extends TestCase
{
    use GroupProjectAccessProfiles;

    private const int HAMMERHEAD_TYPE_ID = 2185;
    private const int SABRE_TYPE_ID = 22456;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int SELL_STRUCTURE_ID = 1_040_000_000_001;
    private const string SELL_STRUCTURE_NAME = 'Ishtar Citadel';
    private const string JITA_NAME = 'Jita IV - Moon 4';
    private const string NOT_A_UUID = 'not-a-uuid';

    private Security&Stub $security;
    private SellMarketComparison&MockObject $comparison;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->comparison = $this->createMock(SellMarketComparison::class);
        $this->clock = new MockClock('2026-11-09 08:30:00');
    }

    public function testForecastWithoutConnectedUserIsUnauthorized(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn(null);

        self::assertSame(self::UNAUTHORIZED, $this->readForecastOf($this->sabreBatch->getId()->toRfc4122()));
    }

    public function testForecastOfAnUnknownProjectIsNotFound(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->readForecastOf(Uuid::v4()->toRfc4122()));
    }

    public function testForecastOfAProjectIdentifiedByANonUuidIsNotFound(): void
    {
        $this->createSabreBatch();
        $this->security->method('getUser')->willReturn($this->userWithProfile(self::ACCEPTED_MEMBER));

        self::assertSame(self::PROJECT_NOT_FOUND, $this->readForecastOf(self::NOT_A_UUID));
    }

    public function testForecastIsRefusedToAStrangerAndToAPendingMemberWithoutAnyMarketLookup(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $this->comparison->expects(self::never())->method('compareAt');
        $this->security->method('getUser')->willReturnOnConsecutiveCalls(
            $this->userWithProfile(self::STRANGER),
            $this->userWithProfile(self::PENDING_MEMBER),
            $this->userWithProfile(self::OWNER_CORPORATION_NON_MEMBER),
        );

        self::assertSame(
            [self::NO_ACCESS, self::NO_ACCESS, self::NO_ACCESS],
            [
                $this->readForecastOf($this->sabreBatch->getId()->toRfc4122()),
                $this->readForecastOf($this->sabreBatch->getId()->toRfc4122()),
                $this->readForecastOf($this->sabreBatch->getId()->toRfc4122()),
            ],
        );
    }

    public function testOwnerAndAcceptedMemberReadTheSameForecast(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $this->comparisonPricesTheSabreBatch();
        $this->security->method('getUser')->willReturnOnConsecutiveCalls($this->sabreBatchOwner, $this->userWithProfile(self::ACCEPTED_MEMBER));

        $forOwner = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);
        $forMember = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertEquals($forOwner, $forMember);
    }

    public function testForecastIsMappedWithSellStructureProductsSortedByNameAndTotals(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $this->security->method('getUser')->willReturn($this->sabreBatchOwner);
        $calls = [];
        $this->comparison->method('compareAt')->willReturnCallback(
            function (User $seller, ?SellStructure $structure, int $typeId, int $quantity, \DateTimeImmutable $today) use (&$calls): SellMarketComparisonResult {
                $calls[] = [$seller, $structure, $typeId, $quantity, $today];

                return $this->pricedSabreBatchComparison($typeId, $quantity);
            },
        );

        $forecast = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertInstanceOf(ProjectSellForecastResource::class, $forecast);
        self::assertSame([
            'projectId' => $this->sabreBatch->getId()->toRfc4122(),
            'sellStructure' => ['id' => self::SELL_STRUCTURE_ID, 'name' => self::SELL_STRUCTURE_NAME],
            'products' => [
                [
                    'typeId' => self::HAMMERHEAD_TYPE_ID,
                    'typeName' => 'Hammerhead II',
                    'quantity' => 6,
                    'structure' => [
                        'name' => self::SELL_STRUCTURE_NAME,
                        'unitPrice' => 800_000.0,
                        'coveragePercent' => null,
                        'feeRate' => 0.04755,
                        'netUnitPrice' => 761_960.0,
                        'netRevenue' => 4_571_760.0,
                        'daysToSell' => 250.0,
                    ],
                    'jita' => [
                        'name' => self::JITA_NAME,
                        'unitPrice' => 752_500.0,
                        'coveragePercent' => 60.0,
                        'feeRate' => 0.04755,
                        'netUnitPrice' => 716_000.0,
                        'netRevenue' => 4_296_000.0,
                        'daysToSell' => 12.5,
                    ],
                ],
                [
                    'typeId' => self::SABRE_TYPE_ID,
                    'typeName' => 'Sabre',
                    'quantity' => 3,
                    'structure' => [
                        'name' => self::SELL_STRUCTURE_NAME,
                        'unitPrice' => null,
                        'coveragePercent' => null,
                        'feeRate' => 0.04755,
                        'netUnitPrice' => null,
                        'netRevenue' => null,
                        'daysToSell' => null,
                    ],
                    'jita' => [
                        'name' => self::JITA_NAME,
                        'unitPrice' => 1_250_000.0,
                        'coveragePercent' => null,
                        'feeRate' => 0.04755,
                        'netUnitPrice' => 1_190_550.0,
                        'netRevenue' => 3_571_650.0,
                        'daysToSell' => 30.0,
                    ],
                ],
            ],
            'totals' => [
                'structureNetRevenue' => null,
                'jitaNetRevenue' => 7_867_650.0,
                'structureUnknownProductCount' => 1,
                'jitaUnknownProductCount' => 0,
                'remainingCost' => 3_300.0,
                'remainingCostUnknownLineCount' => 0,
                'structureProfit' => null,
                'jitaProfit' => 7_864_350.0,
            ],
        ], get_object_vars($forecast));
        self::assertSame($this->sabreBatchOwner, $calls[0][0]);
        self::assertEquals(new SellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME), $calls[0][1]);
        self::assertEquals($this->clock->now(), $calls[0][4]);
    }

    public function testProjectWithoutSellStructureIsMappedOnJitaOnly(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch(withSellStructure: false);
        $this->security->method('getUser')->willReturn($this->sabreBatchOwner);
        $this->comparison->method('compareAt')->willReturnCallback(
            fn (User $seller, ?SellStructure $structure, int $typeId, int $quantity): SellMarketComparisonResult => $this->pricedSabreBatchComparison($typeId, $quantity, withStructure: null !== $structure),
        );

        $forecast = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertArrayHasKey('sellStructure', get_object_vars($forecast));
        self::assertNull($forecast->sellStructure);
        self::assertSame([null, null], array_map(static fn (array $product): ?array => $product['structure'], $forecast->products));
        self::assertSame(
            ['structureNetRevenue' => null, 'jitaNetRevenue' => 7_867_650.0],
            ['structureNetRevenue' => $forecast->totals['structureNetRevenue'], 'jitaNetRevenue' => $forecast->totals['jitaNetRevenue']],
        );
        self::assertSame(1_250_000.0, $forecast->products[1]['jita']['unitPrice']);
        self::assertSame(3_300.0, $forecast->totals['remainingCost']);
        self::assertNull($forecast->totals['structureProfit']);
        self::assertSame(7_864_350.0, $forecast->totals['jitaProfit']);
    }

    public function testProjectWithNothingLeftToSellIsMappedWithoutProductAndWithUnknownTotals(): void
    {
        $this->createSabreBatch();
        $this->stockSabreBatch();
        $this->sabreBatch->addSale($this->sale(self::HAMMERHEAD_TYPE_ID, 6));
        $this->sabreBatch->addSale($this->sale(self::SABRE_TYPE_ID, 3));
        $this->security->method('getUser')->willReturn($this->sabreBatchOwner);
        $this->comparison->expects(self::never())->method('compareAt');

        $forecast = $this->provider()->provide(new Get(), ['projectId' => $this->sabreBatch->getId()->toRfc4122()]);

        self::assertSame([], $forecast->products);
        self::assertSame(
            [
                'structureNetRevenue' => null,
                'jitaNetRevenue' => null,
                'structureUnknownProductCount' => 0,
                'jitaUnknownProductCount' => 0,
                'remainingCost' => 3_300.0,
                'remainingCostUnknownLineCount' => 0,
                'structureProfit' => null,
                'jitaProfit' => null,
            ],
            $forecast->totals,
        );
        self::assertSame(['id' => self::SELL_STRUCTURE_ID, 'name' => self::SELL_STRUCTURE_NAME], $forecast->sellStructure);
    }

    private function readForecastOf(string $projectId): string
    {
        return $this->outcomeOf(fn () => $this->provider()->provide(new Get(), ['projectId' => $projectId]));
    }

    private function provider(): ProjectSellForecastProvider
    {
        return new ProjectSellForecastProvider(
            $this->security,
            $this->sabreBatchProjectRepository(),
            new ProjectAccessChecker($this->sabreBatchMemberRepository()),
            new ProjectSellForecast($this->comparison, new UnsoldProductsOfProject(), new RemainingBomCost()),
            $this->clock,
        );
    }

    private function comparisonPricesTheSabreBatch(): void
    {
        $this->comparison->method('compareAt')->willReturnCallback(
            fn (User $seller, ?SellStructure $structure, int $typeId, int $quantity): SellMarketComparisonResult => $this->pricedSabreBatchComparison($typeId, $quantity),
        );
    }

    private function pricedSabreBatchComparison(int $typeId, int $quantity, bool $withStructure = true): SellMarketComparisonResult
    {
        $rows = match ($typeId) {
            self::HAMMERHEAD_TYPE_ID => [
                new SellMarketRow('jita', self::JITA_NAME, 752_500.0, 0.6, 8.0, 'region_history', 0.04755, 716_000.0, 12.5),
                new SellMarketRow('capital', self::SELL_STRUCTURE_NAME, 800_000.0, null, 0.024, 'structure_estimate', 0.04755, 761_960.0, 250.0),
            ],
            self::SABRE_TYPE_ID => [
                new SellMarketRow('jita', self::JITA_NAME, 1_250_000.0, null, 1.0, 'region_history', 0.04755, 1_190_550.0, 30.0),
                new SellMarketRow('capital', self::SELL_STRUCTURE_NAME, null, null, null, 'structure_estimate', 0.04755, null, null),
            ],
        };

        return new SellMarketComparisonResult($typeId, $quantity, $withStructure ? $rows : [$rows[0]], new ImmediateSale(null, null, null));
    }

    private function stockSabreBatch(bool $withSellStructure = true): void
    {
        if ($withSellStructure) {
            $this->sabreBatch->setSellStructure(self::SELL_STRUCTURE_ID, self::SELL_STRUCTURE_NAME);
        }
        $this->sabreBatch->addBomItem($this->finalJobLine(self::SABRE_TYPE_ID, 'Sabre', 3));
        $this->sabreBatch->addBomItem($this->finalJobLine(self::HAMMERHEAD_TYPE_ID, 'Hammerhead II', 10));
        $this->sabreBatch->addBomItem($this->materialLine(self::TRITANIUM_TYPE_ID, 1_000, 400, 5.5));
        $this->sabreBatch->addSale($this->sale(self::HAMMERHEAD_TYPE_ID, 4));
    }

    private function materialLine(int $typeId, int $requiredQuantity, int $fulfilledQuantity, float $estimatedPrice): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName('Tritanium')
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setEstimatedPrice($estimatedPrice)
            ->setIsJob(false);
    }

    private function finalJobLine(int $typeId, string $typeName, int $requiredQuantity): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setRuns($requiredQuantity);
    }

    private function sale(int $typeId, int $quantity): IndustryProjectSale
    {
        return (new IndustryProjectSale())
            ->setTypeId($typeId)
            ->setTypeName('Sold type')
            ->setQuantity($quantity)
            ->setUnitPrice(1_000.0)
            ->setTotalPrice($quantity * 1_000.0)
            ->setSoldAt(new \DateTimeImmutable('2026-11-01 10:00:00'));
    }
}
