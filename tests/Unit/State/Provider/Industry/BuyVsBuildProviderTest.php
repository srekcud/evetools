<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\BuyVsBuildResource;
use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\BuyVsBuildService;
use App\State\Provider\Industry\BuyVsBuildProvider;
use App\Tests\Unit\Service\Industry\BuildsBuyVsBuildOnGoldenSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(BuyVsBuildProvider::class)]
final class BuyVsBuildProviderTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const int OTHER_SELL_STRUCTURE_ID = 1_041_204_316_528;
    private const int HAIL_L = 12779;
    private const int FERNITE_CARBIDE = 16673;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int ROBOTICS = 9848;

    #[Group('issue-100')]
    public function testWithoutParametersNorSettingsAnalyzesOneRunAtMe10InJitaWithDefaultFees(): void
    {
        $resource = $this->provide(self::HAIL_L, [], $this->goldenUser(), null);

        $this->assertSame(1, $resource->runs);
        $this->assertSame(2940, $this->componentOf($resource, self::FERNITE_CARBIDE)['quantity']);
        $this->assertSame(500.0, $resource->sellPrice);
        $this->assertSame(11632.5, $this->componentOf($resource, self::FERNITE_CARBIDE)['buildJobInstallCost']);
        $this->assertSame(559297.9, $resource->totalProductionCost);
        $this->assertResourceIsTheAnalysisOf(
            $resource,
            self::HAIL_L, 1, 10, EveConstants::JITA_SOLAR_SYSTEM_ID, null,
            EveConstants::DEFAULT_BROKER_FEE_RATE, EveConstants::DEFAULT_SALES_TAX_RATE,
        );
    }

    #[Group('issue-100')]
    public function testWithoutStructureParameterNorPreferredMarketStructureNoStructureIsChosenAndJitaPricesApply(): void
    {
        $resource = $this->provide(self::HAIL_L, [], $this->goldenUser(), null);

        $this->assertNull($resource->sellStructureId);
        $this->assertSame(500.0, $resource->sellPrice);
        $this->assertNull($this->componentOf($resource, self::RAM_AMMUNITION_TECH)['buyCostStructure']);
        $this->assertSame(20000.0, $this->componentOf($resource, self::RAM_AMMUNITION_TECH)['buyCostJita']);
    }

    #[Group('issue-100')]
    public function testPreferredMarketStructureIsExposedAsTheSellStructure(): void
    {
        $user = $this->goldenUser()->setPreferredMarketStructureId(self::SELL_STRUCTURE_ID);

        $resource = $this->provide(self::HAIL_L, [], $user, null);

        $this->assertSame(self::SELL_STRUCTURE_ID, $resource->sellStructureId);
        $this->assertSame(25000.0, $this->componentOf($resource, self::RAM_AMMUNITION_TECH)['buyCostStructure']);
    }

    #[Group('issue-100')]
    public function testStructureQueryParameterIsExposedAsTheSellStructureOverThePreferredMarketStructure(): void
    {
        $user = $this->goldenUser()->setPreferredMarketStructureId(self::OTHER_SELL_STRUCTURE_ID);

        $resource = $this->provide(self::HAIL_L, ['structureId' => (string) self::SELL_STRUCTURE_ID], $user, null);

        $this->assertSame(self::SELL_STRUCTURE_ID, $resource->sellStructureId);
        $this->assertSame(500.0, $resource->sellPrice);
        $this->assertSame(25000.0, $this->componentOf($resource, self::RAM_AMMUNITION_TECH)['buyCostStructure']);
    }

    public function testUserSettingsGiveTheFeesTheFavoriteManufacturingSystemAndThePreferredStructure(): void
    {
        $user = $this->goldenUser()->setPreferredMarketStructureId(self::SELL_STRUCTURE_ID);
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID);

        $resource = $this->provide(self::HAIL_L, [], $user, $settings);

        $this->assertSame(500.0, $resource->sellPrice);
        $this->assertResourceIsTheAnalysisOf($resource, self::HAIL_L, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.01, 0.02);
    }

    public function testQueryParametersOverrideTheSettingsAndRunsAreAtLeastOne(): void
    {
        $user = $this->goldenUser()->setPreferredMarketStructureId(self::SELL_STRUCTURE_ID);
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: EveConstants::JITA_SOLAR_SYSTEM_ID);

        $resource = $this->provide(
            self::HAIL_L,
            ['runs' => '-5', 'me' => '2', 'solarSystemId' => (string) self::FACTORY_SYSTEM_ID, 'structureId' => (string) EveConstants::JITA_STATION_ID],
            $user,
            $settings,
        );

        $this->assertSame(1, $resource->runs);
        $this->assertSame(2940, $this->componentOf($resource, self::FERNITE_CARBIDE)['quantity']);
        $this->assertResourceIsTheAnalysisOf($resource, self::HAIL_L, 1, 2, self::FACTORY_SYSTEM_ID, EveConstants::JITA_STATION_ID, 0.01, 0.02);
    }

    #[Group('issue-100')]
    public function testRunsQueryParameterIsForwardedAsRuns(): void
    {
        $resource = $this->provide(self::HAIL_L, ['runs' => '10'], $this->goldenUser(), null);

        $this->assertSame(10, $resource->runs);
        $this->assertSame(500.0, $resource->sellPrice);
        $this->assertResourceIsTheAnalysisOf(
            $resource,
            self::HAIL_L, 10, 10, EveConstants::JITA_SOLAR_SYSTEM_ID, null,
            EveConstants::DEFAULT_BROKER_FEE_RATE, EveConstants::DEFAULT_SALES_TAX_RATE,
        );
    }

    #[Group('issue-99')]
    public function testReactionJobsTakeTheFavoriteReactionSystemAndManufacturingJobsTheFavoriteManufacturingSystem(): void
    {
        $user = $this->goldenUser();
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID)
            ->setFavoriteReactionSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID);

        $resource = $this->provide(self::FERNITE_CARBIDE, [], $user, $settings);

        $this->assertSame(8332.5, $this->componentOf($resource, self::FERNITE_ALLOY)['buildJobInstallCost']);
        $this->assertSame(7788.0, $this->componentOf($resource, self::CERAMIC_POWDER)['buildJobInstallCost']);
        $this->assertSame(3157.95, $this->componentOf($resource, self::HYDROGEN_FUEL_BLOCK)['buildJobInstallCost']);
        $this->assertNull($resource->sellStructureId);
        $this->assertResourceIsTheAnalysisOf(
            $resource,
            self::FERNITE_CARBIDE, 1, 10, self::FACTORY_SYSTEM_ID, null, 0.01, 0.02,
            reactionSolarSystemId: EveConstants::JITA_SOLAR_SYSTEM_ID,
        );
    }

    #[Group('issue-99')]
    public function testManufacturingProductKeepsTheFavoriteManufacturingSystemWhenAFavoriteReactionSystemIsSet(): void
    {
        $user = $this->goldenUser();
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID)
            ->setFavoriteReactionSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID);

        $resource = $this->provide(self::HAIL_L, [], $user, $settings);

        $this->assertSame(4369.33, $this->componentOf($resource, self::RAM_AMMUNITION_TECH)['buildJobInstallCost']);
        $this->assertSame(11632.5, $this->componentOf($resource, self::FERNITE_CARBIDE)['buildJobInstallCost']);
        $this->assertSame(374792.5, $resource->optimalMixCost);
        $this->assertSame(573813.72, $resource->totalProductionCost);
    }

    #[Group('issue-99')]
    public function testWithoutFavoriteReactionSystemReactionJobsFallBackToTheFavoriteManufacturingSystem(): void
    {
        $user = $this->goldenUser();
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID);

        $resource = $this->provide(self::FERNITE_CARBIDE, [], $user, $settings);

        $this->assertSame(14392.5, $this->componentOf($resource, self::FERNITE_ALLOY)['buildJobInstallCost']);
        $this->assertSame(3157.95, $this->componentOf($resource, self::HYDROGEN_FUEL_BLOCK)['buildJobInstallCost']);
        $this->assertSame(113791.45, $resource->totalProductionCost);
        $this->assertResourceIsTheAnalysisOf($resource, self::FERNITE_CARBIDE, 1, 10, self::FACTORY_SYSTEM_ID, null, 0.01, 0.02);
    }

    #[Group('issue-99')]
    public function testSolarSystemQueryParameterAppliesToReactionJobsOverTheFavoriteReactionSystem(): void
    {
        $user = $this->goldenUser();
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: EveConstants::JITA_SOLAR_SYSTEM_ID)
            ->setFavoriteReactionSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID);

        $resource = $this->provide(self::FERNITE_CARBIDE, ['solarSystemId' => (string) self::FACTORY_SYSTEM_ID], $user, $settings);

        $this->assertSame(14392.5, $this->componentOf($resource, self::FERNITE_ALLOY)['buildJobInstallCost']);
        $this->assertSame(3157.95, $this->componentOf($resource, self::HYDROGEN_FUEL_BLOCK)['buildJobInstallCost']);
        $this->assertSame(113791.45, $resource->totalProductionCost);
        $this->assertResourceIsTheAnalysisOf($resource, self::FERNITE_CARBIDE, 1, 10, self::FACTORY_SYSTEM_ID, null, 0.01, 0.02);
    }

    #[Group('issue-99')]
    public function testFinalReactionJobOfAReactionProductTakesTheFavoriteReactionSystem(): void
    {
        $user = $this->goldenUser();
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID)
            ->setFavoriteReactionSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID);

        $resource = $this->provide(self::FERNITE_CARBIDE, [], $user, $settings);

        $this->assertSame([self::FERNITE_ALLOY, self::CERAMIC_POWDER], $resource->buyTypeIds);
        $this->assertSame(93698.95, $resource->optimalMixCost);
        $this->assertSame(105331.45, $resource->totalProductionCost);
    }

    #[Group('issue-115')]
    public function testProductMissingFromTheSdeReachesTheResourceWithAnUnknownTypeNameAndItsTypeId(): void
    {
        $components = [[
            'typeId' => self::FERNITE_CARBIDE,
            'typeName' => 'Fernite Carbide',
            'quantity' => 1320,
            'stage' => 'intermediate',
            'buildCost' => 52800.0,
            'buildMaterials' => [['typeId' => self::ROBOTICS, 'typeName' => null, 'quantity' => 7, 'unitPrice' => 6100.0, 'totalPrice' => 42700.0]],
            'buildJobInstallCost' => 1850.0,
            'buyCostJita' => 79200.0,
            'buyCostStructure' => null,
            'verdict' => 'build',
            'savings' => 24550.0,
            'savingsPercent' => 31.0,
            'meUsed' => 10,
            'runs' => 2,
            'missingData' => [],
        ]];
        $buyVsBuildService = $this->createStub(BuyVsBuildService::class);
        $buyVsBuildService->method('analyze')->willReturn([
            'typeId' => self::ROBOTICS,
            'typeName' => null,
            'isT2' => false,
            'runs' => 4,
            'totalProductionCost' => 61230.5,
            'sellPrice' => 18900.0,
            'marginPercent' => 19.4,
            'components' => $components,
            'buildAllCost' => 54650.0,
            'buyAllCost' => 79200.0,
            'optimalMixCost' => 54650.0,
            'buildTypeIds' => [self::FERNITE_CARBIDE],
            'buyTypeIds' => [],
            'missingData' => [],
        ]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->goldenUser());
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['runs' => '4']));
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        $resource = (new BuyVsBuildProvider($security, $requestStack, $buyVsBuildService, $settingsRepository))
            ->provide(new Get(), ['typeId' => (string) self::ROBOTICS]);

        $this->assertNull($resource->typeName);
        $this->assertSame(self::ROBOTICS, $resource->typeId);
        $this->assertSame(4, $resource->runs);
        $this->assertSame(61230.5, $resource->totalProductionCost);
        $this->assertSame($components, $resource->components);
    }

    #[Group('issue-8')]
    public function testUnknownCostsAndTheirMissingDataReachTheResource(): void
    {
        $components = [[
            'typeId' => self::FERNITE_CARBIDE,
            'typeName' => 'Fernite Carbide',
            'quantity' => 2700,
            'stage' => 'Reaction',
            'buildCost' => null,
            'buildMaterials' => [['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 100, 'unitPrice' => null, 'totalPrice' => null]],
            'buildJobInstallCost' => 4720.0,
            'buyCostJita' => null,
            'buyCostStructure' => null,
            'verdict' => 'unknown',
            'savings' => null,
            'savingsPercent' => null,
            'meUsed' => 0,
            'runs' => 1,
            'missingData' => [
                ['reason' => 'market_price', 'typeId' => self::CERAMIC_POWDER],
                ['reason' => 'market_price', 'typeId' => self::FERNITE_CARBIDE],
            ],
        ]];
        $buyVsBuildService = $this->createStub(BuyVsBuildService::class);
        $buyVsBuildService->method('analyze')->willReturn([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => 3,
            'totalProductionCost' => null,
            'sellPrice' => 510.0,
            'marginPercent' => null,
            'components' => $components,
            'buildAllCost' => null,
            'buyAllCost' => null,
            'optimalMixCost' => null,
            'buildTypeIds' => [],
            'buyTypeIds' => [],
            'missingData' => [
                ['reason' => 'market_price', 'typeId' => self::CERAMIC_POWDER],
                ['reason' => 'market_price', 'typeId' => self::FERNITE_CARBIDE],
            ],
        ]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->goldenUser());
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['runs' => '3']));
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        $resource = (new BuyVsBuildProvider($security, $requestStack, $buyVsBuildService, $settingsRepository))
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L]);

        $this->assertNull($resource->totalProductionCost);
        $this->assertNull($resource->marginPercent);
        $this->assertNull($resource->buildAllCost);
        $this->assertNull($resource->buyAllCost);
        $this->assertNull($resource->optimalMixCost);
        $this->assertSame($components, $resource->components);
        $this->assertSame(
            [
                ['reason' => 'market_price', 'typeId' => self::CERAMIC_POWDER],
                ['reason' => 'market_price', 'typeId' => self::FERNITE_CARBIDE],
            ],
            $resource->missingData,
        );
    }

    #[Group('issue-7')]
    public function testUnknownJobInstallCostAndItsMissingDataReachTheResource(): void
    {
        $components = [[
            'typeId' => self::FERNITE_CARBIDE,
            'typeName' => 'Fernite Carbide',
            'quantity' => 2940,
            'stage' => 'Reaction',
            'buildCost' => null,
            'buildMaterials' => [['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 100, 'unitPrice' => 260.0, 'totalPrice' => 26000.0]],
            'buildJobInstallCost' => null,
            'buyCostJita' => 188160.0,
            'buyCostStructure' => null,
            'verdict' => 'buy',
            'savings' => null,
            'savingsPercent' => null,
            'meUsed' => 0,
            'runs' => 1,
            'missingData' => [
                ['reason' => 'adjusted_price', 'typeId' => self::CERAMIC_POWDER],
                ['reason' => 'cost_index', 'typeId' => null],
            ],
        ]];
        $buyVsBuildService = $this->createStub(BuyVsBuildService::class);
        $buyVsBuildService->method('analyze')->willReturn([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => 2,
            'totalProductionCost' => null,
            'sellPrice' => 520.0,
            'marginPercent' => null,
            'components' => $components,
            'buildAllCost' => null,
            'buyAllCost' => 188160.0,
            'optimalMixCost' => 188160.0,
            'buildTypeIds' => [],
            'buyTypeIds' => [self::FERNITE_CARBIDE],
            'missingData' => [
                ['reason' => 'adjusted_price', 'typeId' => self::CERAMIC_POWDER],
                ['reason' => 'cost_index', 'typeId' => null],
            ],
        ]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->goldenUser());
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['runs' => '2']));
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);

        $resource = (new BuyVsBuildProvider($security, $requestStack, $buyVsBuildService, $settingsRepository))
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L]);

        $this->assertNull($resource->components[0]['buildJobInstallCost']);
        $this->assertSame($components, $resource->components);
        $this->assertNull($resource->totalProductionCost);
        $this->assertSame(
            [
                ['reason' => 'adjusted_price', 'typeId' => self::CERAMIC_POWDER],
                ['reason' => 'cost_index', 'typeId' => null],
            ],
            $resource->missingData,
        );
    }

    public function testMeQueryParameterIsForwardedForAProductThatIsNotInvented(): void
    {
        $resource = $this->provide(self::RAM_AMMUNITION_TECH, ['me' => '0'], $this->goldenUser(), null);

        $this->assertSame(58272.25, $resource->totalProductionCost);
        $this->assertResourceIsTheAnalysisOf(
            $resource,
            self::RAM_AMMUNITION_TECH, 1, 0, EveConstants::JITA_SOLAR_SYSTEM_ID, null,
            EveConstants::DEFAULT_BROKER_FEE_RATE, EveConstants::DEFAULT_SALES_TAX_RATE,
        );
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(self::HAIL_L, [], null, null);
    }

    private function provide(int $typeId, array $query, ?User $user, ?IndustryUserSettings $settings): BuyVsBuildResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($settings);

        $provider = new BuyVsBuildProvider($security, $requestStack, $this->goldenBuyVsBuildService(), $settingsRepository);

        return $provider->provide(new Get(), ['typeId' => (string) $typeId]);
    }

    private function assertResourceIsTheAnalysisOf(
        BuyVsBuildResource $resource,
        int $typeId,
        int $runs,
        int $meLevel,
        int $solarSystemId,
        ?int $sellStructureId,
        float $brokerFeeRate,
        float $salesTaxRate,
        ?int $reactionSolarSystemId = null,
    ): void {
        $analysis = $this->goldenBuyVsBuildService()->analyze($typeId, $runs, $meLevel, $solarSystemId, $sellStructureId, $brokerFeeRate, $salesTaxRate, null, $reactionSolarSystemId);

        $this->assertSame($analysis, [
            'typeId' => $resource->typeId,
            'typeName' => $resource->typeName,
            'isT2' => $resource->isT2,
            'runs' => $resource->runs,
            'totalProductionCost' => $resource->totalProductionCost,
            'sellPrice' => $resource->sellPrice,
            'marginPercent' => $resource->marginPercent,
            'components' => $resource->components,
            'buildAllCost' => $resource->buildAllCost,
            'buyAllCost' => $resource->buyAllCost,
            'optimalMixCost' => $resource->optimalMixCost,
            'buildTypeIds' => $resource->buildTypeIds,
            'buyTypeIds' => $resource->buyTypeIds,
            'missingData' => $resource->missingData,
        ]);
    }

    private function goldenBuyVsBuildService(): BuyVsBuildService
    {
        return $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [
                self::HAIL_L => 500.0,
                self::RAM_AMMUNITION_TECH => 20000.0,
                self::FERNITE_CARBIDE => 60.0,
                self::HYDROGEN_FUEL_BLOCK => 20000.0,
                34 => 5.0,
                35 => 10.0,
                self::FERNITE_ALLOY => 300.0,
                self::CERAMIC_POWDER => 250.0,
                36 => 50.0,
                37 => 100.0,
                38 => 800.0,
                11399 => 10000.0,
                4051 => 21000.0,
                16659 => 500.0,
                16662 => 450.0,
                16679 => 160.0,
                44 => 110.0,
                3683 => 210.0,
                3689 => 310.0,
                9832 => 410.0,
                self::ROBOTICS => 510.0,
                16272 => 11.0,
                16273 => 21.0,
                16275 => 31.0,
                17889 => 41.0,
                self::STUB_DATACORE_A => 3300.0,
                self::STUB_DATACORE_B => 3900.0,
                16639 => 55.0,
                16642 => 75.0,
                16635 => 25.0,
                16636 => 35.0,
            ],
            structurePrices: [
                self::SELL_STRUCTURE_ID => [self::RAM_AMMUNITION_TECH => 25000.0],
                self::OTHER_SELL_STRUCTURE_ID => [self::HAIL_L => 480.0, self::RAM_AMMUNITION_TECH => 23000.0],
                EveConstants::JITA_STATION_ID => [self::HAIL_L => 450.0, self::RAM_AMMUNITION_TECH => 21000.0],
            ],
            adjustedPrices: [
                34 => 4.0,
                35 => 8.0,
                36 => 40.0,
                37 => 90.0,
                38 => 700.0,
                17889 => 36.0,
                self::HYDROGEN_FUEL_BLOCK => 18000.0,
                self::FERNITE_ALLOY => 280.0,
                self::CERAMIC_POWDER => 230.0,
                self::FERNITE_CARBIDE => 50.0,
                11399 => 9000.0,
                self::RAM_AMMUNITION_TECH => 22000.0,
                4051 => 16000.0,
                16659 => 390.0,
                16662 => 350.0,
                16679 => 145.0,
                44 => 95.0,
                3683 => 190.0,
                3689 => 280.0,
                9832 => 380.0,
                self::ROBOTICS => 470.0,
                16272 => 9.0,
                16273 => 18.0,
                16275 => 27.0,
                16639 => 46.0,
                16642 => 64.0,
                16635 => 17.0,
                16636 => 27.0,
            ],
            costIndices: [
                EveConstants::JITA_SOLAR_SYSTEM_ID => ['manufacturing' => 0.02, 'reaction' => 0.04, 'copying' => 0.01, 'invention' => 0.03],
                self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1, 'copying' => 0.03, 'invention' => 0.06],
            ],
        );
    }

    private function settingsOf(User $user, int $favoriteManufacturingSystemId): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setUser($user)
            ->setFavoriteManufacturingSystemId($favoriteManufacturingSystemId)
            ->setBrokerFeeRate(0.01)
            ->setSalesTaxRate(0.02);
    }

    private function componentOf(BuyVsBuildResource $resource, int $typeId): array
    {
        foreach ($resource->components as $component) {
            if ($component['typeId'] === $typeId) {
                return $component;
            }
        }

        $this->fail("No component {$typeId} in the buy-vs-build resource");
    }
}
