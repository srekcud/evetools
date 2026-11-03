<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Sde\InvType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectShoppingListAndCostEstimationApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;
    use SeedsJitaOrderBooksAndCostIndices;

    private const float ISOTOPE_VOLUME = 0.5;
    private const float ISOTOPE_BEST_SELL_PRICE = 612.5;
    private const float ISOTOPE_NEXT_SELL_PRICE = 640.0;
    private const float ISOTOPE_ADJUSTED_PRICE = 580.0;
    private const float FUEL_BLOCK_ADJUSTED_PRICE = 14_250.0;
    private const float JITA_MANUFACTURING_COST_INDEX = 0.0625;
    private const int DEFAULT_MARKET_STRUCTURE_ID = 1_049_588_174_021;
    private const string DEFAULT_MARKET_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';
    private const array MATERIAL_FIELDS = ['typeId', 'typeName', 'quantity', 'volume', 'totalVolume', 'jitaUnitPrice', 'jitaTotal', 'importCost', 'jitaWithImport', 'structureUnitPrice', 'structureTotal', 'bestLocation', 'bestPrice', 'savings', 'purchasedQuantity', 'extraQuantity', 'jitaWeightedUnitPrice', 'jitaWeightedTotal', 'jitaCoverage', 'isInventionMaterial'];
    private const array TOTALS_FIELDS = ['jita', 'import', 'jitaWithImport', 'structure', 'volume', 'best', 'savingsVsJitaWithImport', 'savingsVsStructure'];

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
        $this->recordEsiRequestsInsteadOfSendingThem();
        $isotope = $this->em->find(InvType::class, self::ISOTOPE_TYPE_ID);
        self::assertNotNull($isotope);
        $isotope->setVolume(self::ISOTOPE_VOLUME);
        $this->em->flush();
    }

    public function testShoppingListBuysTheIsotopesOfSixFuelBlockRunsAtJitaWithTheDefaultImportCost(): void
    {
        $this->seedIsotopeSellOrders();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/shopping-list');

        self::assertResponseStatusCodeSame(200);
        $shoppingList = $this->responseJson();
        self::assertSame(
            [[self::ISOTOPE_TYPE_ID, 'Test Isotope', 54, 0.5, 27.0, 612.5, 33_075.0, 32_400.0, 65_475.0, null, null, 'jita', 65_475.0, null, 0, 0, 626.25, 33_817.5, 1.0, false]],
            array_map(fn (array $material): array => $this->fieldsOf($material, self::MATERIAL_FIELDS), $shoppingList['materials']),
        );
        self::assertSame([33_075.0, 32_400.0, 65_475.0, null, 27.0, 65_475.0, 0.0, 0.0], $this->fieldsOf($shoppingList['totals'], self::TOTALS_FIELDS));
        self::assertSame(
            [self::DEFAULT_MARKET_STRUCTURE_ID, self::DEFAULT_MARKET_STRUCTURE_NAME, false, false, null, 1200.0, null, [], [], []],
            $this->fieldsOf($shoppingList, ['structureId', 'structureName', 'structureAccessible', 'structureFromCache', 'structureLastSync', 'transportCostPerM3', 'priceError', 'missingVolumeTypeIds', 'missingPriceTypeIds', 'failedProducts']),
        );
        self::assertSame([], $this->esiRequests);
    }

    public function testShoppingListChargesTheTransportCostPerCubicMetreGivenInTheQuery(): void
    {
        $this->seedIsotopeSellOrders();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/shopping-list?transportCost=250');

        self::assertResponseStatusCodeSame(200);
        $shoppingList = $this->responseJson();
        self::assertSame([[6_750.0, 39_825.0, 39_825.0]], array_map(fn (array $material): array => $this->fieldsOf($material, ['importCost', 'jitaWithImport', 'bestPrice']), $shoppingList['materials']));
        self::assertSame([250.0, 6_750.0, 39_825.0], [$shoppingList['transportCostPerM3'], $shoppingList['totals']['import'], $shoppingList['totals']['jitaWithImport']]);
    }

    #[Group('issue-206')]
    public function testShoppingListCurrentlyReadsATransportCostThatIsNotANumberAsZeroIssue206(): void
    {
        $this->seedIsotopeSellOrders();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/shopping-list?transportCost=abc');

        self::assertResponseStatusCodeSame(200);
        $shoppingList = $this->responseJson();
        self::assertSame([0.0, 0.0, 33_075.0], [$shoppingList['transportCostPerM3'], $shoppingList['totals']['import'], $shoppingList['totals']['jitaWithImport']]);
    }

    #[Group('issue-206')]
    public function testShoppingListCurrentlyReadsAStructureIdThatIsNotANumberAsStructureZeroIssue206(): void
    {
        $this->seedIsotopeSellOrders();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/shopping-list?structureId=abc');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([0, self::DEFAULT_MARKET_STRUCTURE_NAME], $this->fieldsOf($this->responseJson(), ['structureId', 'structureName']));
    }

    public function testCostEstimationAddsTheIsotopesAtJitaAndTheInstallCostsOfTheCarrierAndFuelBlockJobsInJita(): void
    {
        $this->seedIsotopeSellOrders();
        $this->seedAdjustedPrices([self::FUEL_BLOCK_TYPE_ID => self::FUEL_BLOCK_ADJUSTED_PRICE, self::ISOTOPE_TYPE_ID => self::ISOTOPE_ADJUSTED_PRICE]);
        $this->seedJitaCostIndex('manufacturing', self::JITA_MANUFACTURING_COST_INDEX);

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/cost-estimation');

        self::assertResponseStatusCodeSame(200);
        $estimation = $this->responseJson();
        self::assertSame(
            [[self::ISOTOPE_TYPE_ID, 'Test Isotope', 54, 612.5, 33_075.0]],
            array_map(fn (array $material): array => $this->fieldsOf($material, ['typeId', 'typeName', 'quantity', 'unitPrice', 'totalPrice']), $estimation['materials']),
        );
        self::assertSame(
            [
                [$this->idOf($this->carrierStep), self::CARRIER_TYPE_ID, 'Test Carrier', 30_000_142, 'System #30000142', 0.0625, 2, null, []],
                [$this->idOf($this->fuelBlockStep), self::FUEL_BLOCK_TYPE_ID, 'Test Fuel Block', 30_000_142, 'System #30000142', 0.0625, 6, null, []],
            ],
            array_map(fn (array $job): array => $this->fieldsOf($job, ['stepId', 'productTypeId', 'productName', 'solarSystemId', 'systemName', 'costIndex', 'runs', 'unknownReason', 'missingAdjustedPriceTypeIds']), $estimation['jobInstallSteps']),
        );
        self::assertEqualsWithDelta([359_100.0, 3_288.6], array_column($estimation['jobInstallSteps'], 'installCost'), 0.000_001);
        self::assertSame([33_075.0, 0.0, 2, []], $this->fieldsOf($estimation, ['materialCost', 'bpoCost', 'outputQuantity', 'failedProducts']));
        self::assertEqualsWithDelta([362_388.6, 395_463.6, 197_731.8], [$estimation['jobInstallCost'], $estimation['totalCost'], $estimation['perUnit']], 0.000_001);
        self::assertSame([], $this->esiRequests);
    }

    #[Group('issue-206')]
    public function testCostEstimationWithoutTheJitaManufacturingCostIndexCurrentlyOmitsTheUnknownInstallAndTotalCostsFromTheBodyIssue206(): void
    {
        $this->seedIsotopeSellOrders();
        $this->seedAdjustedPrices([self::FUEL_BLOCK_TYPE_ID => self::FUEL_BLOCK_ADJUSTED_PRICE, self::ISOTOPE_TYPE_ID => self::ISOTOPE_ADJUSTED_PRICE]);

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/cost-estimation');

        self::assertResponseStatusCodeSame(200);
        $estimation = $this->responseJson();
        self::assertSame(
            [[null, 'missing_cost_index'], [null, 'missing_cost_index']],
            array_map(fn (array $job): array => $this->fieldsOf($job, ['installCost', 'unknownReason']), $estimation['jobInstallSteps']),
        );
        self::assertSame([33_075.0, 'absent', 'absent', 'absent'], $this->fieldsOf($estimation, ['materialCost', 'jobInstallCost', 'totalCost', 'perUnit']));
    }

    #[Group('issue-206')]
    public function testCostEstimationCurrentlyPricesIsotopesWithoutAnyJitaSellOrderAtZeroIssue206(): void
    {
        $this->seedJitaSellOrderBooks([]);
        $this->seedNoJitaSellOrderFor(self::ISOTOPE_TYPE_ID);
        $this->seedAdjustedPrices([self::FUEL_BLOCK_TYPE_ID => self::FUEL_BLOCK_ADJUSTED_PRICE, self::ISOTOPE_TYPE_ID => self::ISOTOPE_ADJUSTED_PRICE]);
        $this->seedJitaCostIndex('manufacturing', self::JITA_MANUFACTURING_COST_INDEX);

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/cost-estimation');

        self::assertResponseStatusCodeSame(200);
        $estimation = $this->responseJson();
        self::assertSame([[54, 0.0, 0.0]], array_map(fn (array $material): array => $this->fieldsOf($material, ['quantity', 'unitPrice', 'totalPrice']), $estimation['materials']));
        self::assertSame(0.0, $estimation['materialCost']);
        self::assertEqualsWithDelta(362_388.6, $estimation['totalCost'], 0.000_001);
        self::assertSame([], $this->esiRequests);
    }

    private function seedIsotopeSellOrders(): void
    {
        $this->seedJitaSellOrderBooks([
            self::ISOTOPE_TYPE_ID => [
                ['price' => self::ISOTOPE_BEST_SELL_PRICE, 'volume' => 27],
                ['price' => self::ISOTOPE_NEXT_SELL_PRICE, 'volume' => 1_000],
            ],
        ]);
    }
}
