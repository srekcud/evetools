<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockpileService::class)]
final class StockpileStatusLowTargetProposalTest extends TestCase
{
    use BuildsStockpileOnWidgetSde;

    private const int FRAME_TARGET = 10;
    private const int FRAME_TARGET_QUARTER_STOCKED = 8;
    private const int ORE_TARGET = 10;

    private array $targets = [];
    private array $stockByTypeId = [];

    private StockpileService $service;
    private User $pilot;

    protected function setUp(): void
    {
        $this->useWidgetRecipes();
        $this->useTheTwoFrameFormula();
        $this->pilot = $this->pilotWithId();

        $targetRepository = $this->createStub(IndustryStockpileTargetRepository::class);
        $targetRepository->method('findByUser')->willReturnCallback(fn (): array => $this->targets);
        $importRepository = $this->createStub(IndustryStockpileImportRepository::class);
        $importRepository->method('findByUserInCreationOrder')->willReturn([]);
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturnCallback(
            fn (): array => array_map(static fn (int $stock): array => ['total' => $stock, 'locations' => []], $this->stockByTypeId),
        );
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturn([]);

        $this->service = new StockpileService(
            $targetRepository,
            $assetRepository,
            $jitaMarketService,
            $this->productionPlanningOnWidgetSde(),
            $this->userStructuresOfThePilot(),
            $this->productRepositoryOnWidgetSde(),
            $this->createStub(EntityManagerInterface::class),
            $importRepository,
            $this->startingStockReducerOnWidgetSde(),
            $this->blacklistOfThePilot(),
        );
    }

    public function testTargetBelowAQuarterProposesTheRunsThatCoverItsDeficit(): void
    {
        $this->addManualTarget(self::FRAME, self::FRAME_TARGET, 'component');
        $this->stockByTypeId = [self::FRAME => 2];

        self::assertSame(4, $this->suggestedProjectRunsOf(self::FRAME));
    }

    public function testRunsAreRoundedUpSoThatTheProjectNeverFallsShortOfTheTarget(): void
    {
        $this->addManualTarget(self::FRAME, self::FRAME_TARGET, 'component');
        $this->stockByTypeId = [self::FRAME => 1];

        self::assertSame(5, $this->suggestedProjectRunsOf(self::FRAME));
    }

    public function testTargetWithoutAnyStockProposesTheRunsOfItsWholeTarget(): void
    {
        $this->addManualTarget(self::FRAME, self::FRAME_TARGET, 'component');

        self::assertSame(5, $this->suggestedProjectRunsOf(self::FRAME));
    }

    public function testTargetExactlyAtAQuarterProposesNothing(): void
    {
        $this->addManualTarget(self::FRAME, self::FRAME_TARGET_QUARTER_STOCKED, 'component');
        $this->stockByTypeId = [self::FRAME => 2];

        self::assertNull($this->suggestedProjectRunsOf(self::FRAME));
    }

    public function testMetTargetProposesNothing(): void
    {
        $this->addManualTarget(self::FRAME, self::FRAME_TARGET, 'component');
        $this->stockByTypeId = [self::FRAME => self::FRAME_TARGET];

        self::assertNull($this->suggestedProjectRunsOf(self::FRAME));
    }

    public function testLowTargetOfATypeNoJobProducesProposesNothingInsteadOfAGuessedProject(): void
    {
        $this->addManualTarget(self::ORE, self::ORE_TARGET, 'raw_material');

        self::assertNull($this->suggestedProjectRunsOf(self::ORE));
    }

    public function testProposalDoesNotChangeTheDeficitNorTheShoppingList(): void
    {
        $this->addManualTarget(self::FRAME, self::FRAME_TARGET, 'component');
        $this->stockByTypeId = [self::FRAME => 2];

        $status = $this->service->getStockpileStatus($this->pilot);

        self::assertSame([self::FRAME => 8], array_column($status['shoppingList'], 'deficit', 'typeId'));
    }

    private function suggestedProjectRunsOf(int $typeId): ?int
    {
        $status = $this->service->getStockpileStatus($this->pilot);

        foreach ($status['stages'] as $stage) {
            foreach ($stage['items'] as $item) {
                if ($item['typeId'] === $typeId) {
                    self::assertArrayHasKey('suggestedProjectRuns', $item);

                    return $item['suggestedProjectRuns'];
                }
            }
        }
        self::fail("No stockpile item for type {$typeId}");
    }

    private function addManualTarget(int $typeId, int $targetQuantity, string $stage): void
    {
        $this->targets[] = (new IndustryStockpileTarget())
            ->setUser($this->pilot)
            ->setTypeId($typeId)
            ->setTypeName(self::TYPE_NAMES[$typeId])
            ->setTargetQuantity($targetQuantity)
            ->setStage($stage)
            ->setSourceProductTypeId(null);
    }
}
