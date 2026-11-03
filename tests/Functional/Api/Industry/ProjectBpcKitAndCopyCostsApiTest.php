<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('issue-206')]
final class ProjectBpcKitAndCopyCostsApiTest extends WebTestCase
{
    use BuildsCarrierProjectWithFuelBlockSteps;
    use SeedsJitaOrderBooksAndCostIndices;

    private const int T1_CARRIER_TYPE_ID = 999_206_630;
    private const int T1_CARRIER_BLUEPRINT_TYPE_ID = 999_206_631;
    private const int DATACORE_TYPE_ID = 999_206_660;
    private const int ISOTOPES_PER_T1_CARRIER_RUN = 50;
    private const int CARRIER_BLUEPRINT_RUNS_PER_INVENTION = 3;
    private const float INVENTION_PROBABILITY = 0.25;
    private const int DATACORES_PER_INVENTION = 4;
    private const float DATACORE_SELL_PRICE = 125_000.0;
    private const float T1_CARRIER_ADJUSTED_PRICE = 2_000_000.0;
    private const float FUEL_BLOCK_ADJUSTED_PRICE = 14_250.0;
    private const float JITA_COPYING_COST_INDEX = 0.037_1;
    private const float JITA_INVENTION_COST_INDEX = 0.019_3;
    private const int FUEL_BLOCK_COPY_RUNS = 6;
    private const array DECRYPTOR_TYPE_IDS = [34_201, 34_202, 34_203, 34_204, 34_205, 34_206, 34_207, 34_208];

    protected function setUp(): void
    {
        $this->setUpCarrierProjectWithFuelBlockStep();
        $this->recordEsiRequestsInsteadOfSendingThem();
    }

    public function testBpcKitOfTheCarrierInventedFromTheT1CarrierBlueprintCountsTwelveAttemptsForThreeCopies(): void
    {
        $this->seedCarrierInventionFromTheT1CarrierBlueprint();
        $this->seedInventionPricesAndCostIndices();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/bpc-kit?desired_bpc_count=3');

        self::assertResponseStatusCodeSame(200);
        $bpcKit = $this->responseJson();
        self::assertTrue($bpcKit['isT2']);
        self::assertCount(1, $bpcKit['inventions']);
        $invention = $bpcKit['inventions'][0];
        self::assertSame([self::CARRIER_TYPE_ID, 'Test Carrier', 0.25, null, 3], $this->fieldsOf($invention, ['productTypeId', 'productName', 'baseProbability', 'unknownReason', 'desiredSuccesses']));
        self::assertSame([[self::DATACORE_TYPE_ID, 'Test Datacore', 4, 125_000.0, 500_000.0]], array_map(fn (array $datacore): array => $this->fieldsOf($datacore, ['typeId', 'typeName', 'quantity', 'unitPrice', 'totalPrice']), $invention['datacores']));
        $withoutDecryptor = $invention['decryptorOptions'][0];
        self::assertSame([null, 'No Decryptor', 2, 4, 3, 0.25, 12, [], [], null], $this->fieldsOf($withoutDecryptor, ['decryptorTypeId', 'decryptorName', 'me', 'te', 'runs', 'probability', 'expectedAttempts', 'missingPriceTypeIds', 'missingAdjustedPriceTypeIds', 'unknownReason']));
        self::assertSame([500_000.0, 0.0], [$withoutDecryptor['costBreakdown']['datacores'], $withoutDecryptor['costBreakdown']['decryptor']]);
        self::assertEqualsWithDelta([1_487.71, 2_113.56, 503_601.27, 6_043_215.24], [$withoutDecryptor['costBreakdown']['copyCost'], $withoutDecryptor['costBreakdown']['inventionInstall'], $withoutDecryptor['costPerAttempt'], $withoutDecryptor['totalCost']], 0.000_001);
        self::assertSame(
            [
                [34_201, 'Accelerant Decryptor', 4, 14, 4, 0.3, 10],
                [34_202, 'Attainment Decryptor', 1, 8, 7, 0.45, 7],
                [34_203, 'Augmentation Decryptor', 0, 6, 12, 0.15, 20],
                [34_204, 'Parity Decryptor', 3, 2, 6, 0.375, 8],
                [34_205, 'Process Decryptor', 5, 10, 3, 0.275, 11],
                [34_206, 'Symmetry Decryptor', 3, 12, 5, 0.25, 12],
                [34_207, 'Optimized Attainment Decryptor', 3, 2, 5, 0.475, 7],
                [34_208, 'Optimized Augmentation Decryptor', 4, 4, 10, 0.225, 14],
            ],
            array_map(fn (array $option): array => $this->fieldsOf($option, ['decryptorTypeId', 'decryptorName', 'me', 'te', 'runs', 'probability', 'expectedAttempts']), \array_slice($invention['decryptorOptions'], 1)),
        );
        self::assertSame(
            array_map(static fn (int $decryptorTypeId): array => [null, null, [$decryptorTypeId], 'missing_market_price'], self::DECRYPTOR_TYPE_IDS),
            array_map(fn (array $option): array => $this->fieldsOf($option, ['totalCost', 'costPerAttempt', 'missingPriceTypeIds', 'unknownReason']), \array_slice($invention['decryptorOptions'], 1)),
        );
        self::assertSame(null, $bpcKit['summary']['bestDecryptorTypeId']);
        self::assertEqualsWithDelta([6_043_215.24, 6_043_215.24], [$bpcKit['summary']['totalInventionCost'], $bpcKit['summary']['totalBpcKitCost']], 0.000_001);
        self::assertSame([], $this->esiRequests);
    }

    public function testBpcKitOfACarrierWithoutInventionIsNotT2AndCostsNothing(): void
    {
        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/bpc-kit');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [false, [], ['totalInventionCost' => 0.0, 'bestDecryptorTypeId' => null, 'totalBpcKitCost' => 0.0]],
            $this->fieldsOf($this->responseJson(), ['isT2', 'inventions', 'summary']),
        );
    }

    #[Group('issue-206')]
    public function testBpcKitCurrentlyReadsADesiredBpcCountOfZeroAsOneCopyIssue206(): void
    {
        $this->seedCarrierInventionFromTheT1CarrierBlueprint();
        $this->seedInventionPricesAndCostIndices();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/bpc-kit?desired_bpc_count=0');

        self::assertResponseStatusCodeSame(200);
        $invention = $this->responseJson()['inventions'][0];
        self::assertSame([1, 4], [$invention['desiredSuccesses'], $invention['decryptorOptions'][0]['expectedAttempts']]);
    }

    public function testBpcKitWithADesiredBpcCountThatIsNotANumberIsABadRequest(): void
    {
        $this->seedCarrierInventionFromTheT1CarrierBlueprint();
        $this->seedInventionPricesAndCostIndices();

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/bpc-kit?desired_bpc_count=abc');

        self::assertResponseStatusCodeSame(400);
    }

    public function testCopyCostsChargeTheSixRunsOfTheFuelBlockBlueprintCopyInJita(): void
    {
        $this->addSixRunCopyOfTheFuelBlockBlueprint();
        $this->seedAdjustedPrices([self::FUEL_BLOCK_TYPE_ID => self::FUEL_BLOCK_ADJUSTED_PRICE]);
        $this->seedJitaCostIndex('copying', self::JITA_COPYING_COST_INDEX);

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/copy-costs');

        self::assertResponseStatusCodeSame(200);
        $copyCosts = $this->responseJson();
        self::assertSame(
            [[self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, 'Test Fuel Block Blueprint', 'Test Fuel Block', 6, 1]],
            array_map(fn (array $copy): array => $this->fieldsOf($copy, ['blueprintTypeId', 'blueprintName', 'productTypeName', 'runs', 'depth']), $copyCosts['copies']),
        );
        self::assertEqualsWithDelta([[2_543.984_1], 2_543.984_1], [array_column($copyCosts['copies'], 'cost'), $copyCosts['totalCopyCost']], 0.000_001);
        self::assertSame([], $this->esiRequests);
    }

    #[Group('issue-206')]
    public function testCopyCostsCurrentlyChargeNothingWithoutTheJitaCopyingCostIndexIssue206(): void
    {
        $this->addSixRunCopyOfTheFuelBlockBlueprint();
        $this->seedAdjustedPrices([self::FUEL_BLOCK_TYPE_ID => self::FUEL_BLOCK_ADJUSTED_PRICE]);

        $this->client->loginUser($this->owner, 'api');
        $this->request('GET', $this->projectUri().'/copy-costs');

        self::assertResponseStatusCodeSame(200);
        $copyCosts = $this->responseJson();
        self::assertSame([[6, 0.0]], array_map(fn (array $copy): array => $this->fieldsOf($copy, ['runs', 'cost']), $copyCosts['copies']));
        self::assertSame(0.0, $copyCosts['totalCopyCost']);
    }

    private function seedCarrierInventionFromTheT1CarrierBlueprint(): void
    {
        $carrierGroup = $this->em->find(InvGroup::class, self::CARRIER_GROUP_ID);
        self::assertNotNull($carrierGroup);
        $this->type(self::T1_CARRIER_TYPE_ID, 'Test T1 Carrier', $carrierGroup);
        $this->type(self::T1_CARRIER_BLUEPRINT_TYPE_ID, 'Test T1 Carrier Blueprint', $carrierGroup);
        $this->type(self::DATACORE_TYPE_ID, 'Test Datacore', $carrierGroup);
        $this->recipe(IndustryActivityType::Manufacturing, self::T1_CARRIER_BLUEPRINT_TYPE_ID, self::T1_CARRIER_TYPE_ID, 1, self::ISOTOPE_TYPE_ID, self::ISOTOPES_PER_T1_CARRIER_RUN);
        $this->em->persist((new IndustryActivityProduct())
            ->setTypeId(self::T1_CARRIER_BLUEPRINT_TYPE_ID)
            ->setActivityId(IndustryActivityType::Invention->value)
            ->setProductTypeId(self::CARRIER_BLUEPRINT_TYPE_ID)
            ->setQuantity(self::CARRIER_BLUEPRINT_RUNS_PER_INVENTION)
            ->setProbability(self::INVENTION_PROBABILITY));
        $this->em->persist((new IndustryActivityMaterial())
            ->setTypeId(self::T1_CARRIER_BLUEPRINT_TYPE_ID)
            ->setActivityId(IndustryActivityType::Invention->value)
            ->setMaterialTypeId(self::DATACORE_TYPE_ID)
            ->setQuantity(self::DATACORES_PER_INVENTION));
        $this->em->flush();
    }

    private function seedInventionPricesAndCostIndices(): void
    {
        $this->seedJitaSellOrderBooks([self::DATACORE_TYPE_ID => [['price' => self::DATACORE_SELL_PRICE, 'volume' => 1_000]]]);
        $this->seedNoJitaSellOrderFor(...self::DECRYPTOR_TYPE_IDS);
        $this->seedAdjustedPrices([self::T1_CARRIER_TYPE_ID => self::T1_CARRIER_ADJUSTED_PRICE, self::FUEL_BLOCK_TYPE_ID => self::FUEL_BLOCK_ADJUSTED_PRICE]);
        $this->seedJitaCostIndex('copying', self::JITA_COPYING_COST_INDEX);
        $this->seedJitaCostIndex('invention', self::JITA_INVENTION_COST_INDEX);
    }

    private function addSixRunCopyOfTheFuelBlockBlueprint(): void
    {
        $fuelBlockGroup = $this->em->find(InvGroup::class, self::FUEL_BLOCK_GROUP_ID);
        self::assertNotNull($fuelBlockGroup);
        $this->em->persist((new InvType())->setTypeId(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID)->setTypeName('Test Fuel Block Blueprint')->setGroup($fuelBlockGroup));
        $this->step(self::FUEL_BLOCK_BLUEPRINT_TYPE_ID, self::FUEL_BLOCK_TYPE_ID, self::FUEL_BLOCK_COPY_RUNS, self::FUEL_BLOCK_COPY_RUNS, 1)->setActivityType('copy');
        $this->em->flush();
    }
}
