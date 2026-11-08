<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Constant\EveConstants;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\JitaPurchase;
use App\Industry\Application\MaterialSourcing;
use App\Industry\Application\MaterialSourcingComparator;
use App\Industry\Application\MaterialToSource;
use App\Industry\Application\SourcingSide;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Quantity;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryStructureMarketPrices;
use App\Tests\Unit\Industry\Application\Double\RecordingMarketPrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialSourcingComparator::class)]
#[CoversClass(MaterialSourcing::class)]
#[CoversClass(JitaPurchase::class)]
#[CoversClass(MissingData::class)]
#[Group('issue-466')]
final class MaterialSourcingComparatorTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private const int STRUCTURE_ID = 1_035_466_543_993;
    private const int MANUFACTURING_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const int MANUFACTURING_JUMPS = 9;
    private const int REACTION_JUMPS = 2;
    private const float DELTA = 0.001;

    public function testExampleAStructureCheaperThanJitaWithImportIsBoughtAtTheStructureAndSavesTheDifference(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertCount(1, $sourcings);
        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, 13_500.0, 23_500.0, 20_000.0, 3_500.0, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEqualsWithDelta(20_000.0, $sourcings[0]->bestCost()->amount()->amount, self::DELTA);
    }

    public function testExampleBJitaWithImportCheaperThanTheStructureIsBoughtAtJita(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::TRITANIUM => 5.0],
            structurePrices: [self::TRITANIUM => 20.0],
            volumes: [self::TRITANIUM => 0.01],
        )->compare([$this->material(self::TRITANIUM, 10_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::TRITANIUM, SourcingSide::Jita, 50_000.0, 135_000.0, 185_000.0, 200_000.0, 15_000.0, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEqualsWithDelta(185_000.0, $sourcings[0]->bestCost()->amount()->amount, self::DELTA);
    }

    public function testExampleCTieBetweenJitaWithImportAndTheStructureGoesToJitaAndSavesNothing(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::MEXALLON => 100.0],
            structurePrices: [self::MEXALLON => 400.0],
            volumes: [self::MEXALLON => 1.0],
            jumps: [self::REACTION_SYSTEM_ID => self::REACTION_JUMPS],
        )->compare([new MaterialToSource(self::MEXALLON, new Quantity(100), ActivityKind::Reaction)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::MEXALLON, SourcingSide::Jita, 10_000.0, 30_000.0, 40_000.0, 40_000.0, 0.0, false, self::REACTION_JUMPS, 1.0],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
    }

    public function testExampleDMaterialWithoutStructurePriceGoesToJitaAndIsFlaggedAsFallback(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::TRITANIUM => 5.0],
            volumes: [self::PYERITE => 0.01],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Jita, 10_000.0, 13_500.0, 23_500.0, null, null, true, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::structurePrice(self::PYERITE)], $sourcings[0]->structureCost->missingData);
    }

    public function testExampleEMaterialWithoutJitaPriceIsBoughtAtTheStructureAndKeepsTheMissingJitaPrice(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, null, 13_500.0, null, 20_000.0, null, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::marketPrice(self::PYERITE)], $sourcings[0]->jitaWithImportCost()->missingData);
    }

    public function testExampleFMaterialWithoutVolumeHasAnUnknownImportAndIsBoughtAtTheStructure(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, null, null, 20_000.0, null, false, self::MANUFACTURING_JUMPS, null],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::itemVolume(self::PYERITE)], $sourcings[0]->importCost->missingData);
    }

    public function testExampleGWorkshopSystemWithoutRouteFromJitaLeavesTheImportUnknown(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
            jumps: [],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, null, null, 20_000.0, null, false, null, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::jumpRoute()], $sourcings[0]->importCost->missingData);
    }

    public function testExampleHNeitherSidePricedLeavesTheSideUnknown(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [],
            structurePrices: [self::TRITANIUM => 5.0],
            volumes: [self::PYERITE => 0.01],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertSame(SourcingSide::Unknown, $sourcings[0]->side);
        self::assertNull($sourcings[0]->saving);
        self::assertFalse($sourcings[0]->bestCost()->isKnown());
    }

    public function testExampleIWithoutPreferredStructureEveryMaterialGoesToJitaWithoutComparisonOrFallbackFlag(): void
    {
        $structurePrices = new InMemoryStructureMarketPrices([self::STRUCTURE_ID => [self::PYERITE => 1.0]]);

        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices([self::PYERITE => 10.0]),
            $structurePrices,
            [self::PYERITE => 0.01],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare([$this->material(self::PYERITE, 1_000)], null, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Jita, 10_000.0, 13_500.0, 23_500.0, null, null, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertSame([], $structurePrices->requests);
    }

    public function testJitaIsPricedOnTheRemainingQuantitiesAndTheStructureIsAskedOnceForAllTypes(): void
    {
        $marketPrices = new RecordingMarketPrices(new InMemoryMarketPrices([self::PYERITE => 10.0, self::TRITANIUM => 5.0]));
        $structurePrices = new InMemoryStructureMarketPrices([self::STRUCTURE_ID => []]);

        $this->comparatorWith($marketPrices, $structurePrices, [], [])->compare(
            [$this->material(self::PYERITE, 1_000), $this->material(self::TRITANIUM, 250)],
            self::STRUCTURE_ID,
            $this->favoriteSystems(),
        );

        self::assertSame([[self::PYERITE => 1_000.0, self::TRITANIUM => 250.0]], $marketPrices->requests);
        self::assertSame([[self::STRUCTURE_ID, [self::PYERITE, self::TRITANIUM]]], $structurePrices->requests);
    }

    public function testPartialJitaCoverageIsKeptOnTheJitaWithImportCost(): void
    {
        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices([self::PYERITE => 10.0], [self::PYERITE => 0.6]),
            new InMemoryStructureMarketPrices([self::STRUCTURE_ID => []]),
            [self::PYERITE => 0.01],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        $partialPrices = $sourcings[0]->jitaWithImportCost()->partialPrices;
        self::assertCount(1, $partialPrices);
        self::assertSame(self::PYERITE, $partialPrices[0]->typeId);
    }

    public function testNoMaterialAsksForNothingAndReturnsNothing(): void
    {
        $marketPrices = new RecordingMarketPrices(new InMemoryMarketPrices([]));
        $structurePrices = new InMemoryStructureMarketPrices([self::STRUCTURE_ID => []]);

        $sourcings = $this->comparatorWith($marketPrices, $structurePrices, [], [])->compare([], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertSame([], $sourcings);
        self::assertSame([], $marketPrices->requests);
        self::assertSame([], $structurePrices->requests);
    }

    /**
     * @param array<int, float> $jitaPrices
     * @param array<int, float> $structurePrices
     * @param array<int, float> $volumes
     * @param array<int, int>   $jumps
     */
    private function comparator(array $jitaPrices, array $structurePrices, array $volumes, ?array $jumps = null): MaterialSourcingComparator
    {
        return $this->comparatorWith(
            new InMemoryMarketPrices($jitaPrices),
            new InMemoryStructureMarketPrices([self::STRUCTURE_ID => $structurePrices]),
            $volumes,
            $jumps ?? [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        );
    }

    /**
     * @param array<int, float> $volumes
     * @param array<int, int>   $jumps
     */
    private function comparatorWith(\App\Industry\Application\MarketPrices $marketPrices, InMemoryStructureMarketPrices $structurePrices, array $volumes, array $jumps): MaterialSourcingComparator
    {
        return new MaterialSourcingComparator(
            $marketPrices,
            $structurePrices,
            new InMemoryItemVolumes($volumes),
            new InMemoryJumpDistances([EveConstants::JITA_SOLAR_SYSTEM_ID => $jumps]),
        );
    }

    private function favoriteSystems(): FavoriteSystems
    {
        return new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID);
    }

    private function material(int $typeId, int $quantity): MaterialToSource
    {
        return new MaterialToSource($typeId, new Quantity($quantity), ActivityKind::Manufacturing);
    }

    /**
     * @return list<mixed>
     */
    private function rowOf(MaterialSourcing $sourcing): array
    {
        return [
            $sourcing->typeId,
            $sourcing->side,
            $sourcing->jitaCost->isKnown() ? $sourcing->jitaCost->amount()->amount : null,
            $sourcing->importCost->isKnown() ? $sourcing->importCost->amount()->amount : null,
            $sourcing->jitaWithImportCost()->isKnown() ? $sourcing->jitaWithImportCost()->amount()->amount : null,
            $sourcing->structureCost->isKnown() ? $sourcing->structureCost->amount()->amount : null,
            $sourcing->saving?->amount,
            $sourcing->structureFallback,
            $sourcing->jumps,
            $sourcing->volumePerUnit,
        ];
    }
}
