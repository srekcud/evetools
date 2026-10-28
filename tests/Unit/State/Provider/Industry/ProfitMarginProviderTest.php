<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Industry\Application\Decryptors;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\ProfitMarginService;
use App\State\Provider\Industry\ProfitMarginProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Characterization of the /industry/profit-margin/{typeId} defaults, before ProfitMarginService moves to the industry engine (spec §7, rank 1).
 * The computation itself is covered by ProfitMarginGoldenCharacterizationTest.
 */
#[CoversClass(ProfitMarginProvider::class)]
class ProfitMarginProviderTest extends TestCase
{
    private const int HAIL_L_TYPE_ID = 12779;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1035466617946;
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30002510;
    private const int FAVORITE_REACTION_SYSTEM_ID = 30000144;
    private const int REQUESTED_SYSTEM_ID = 30002187;
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int NITROGEN_ISOTOPES_TYPE_ID = 17888;
    private const int FERNITE_CARBIDE_TYPE_ID = 16673;
    private const int HYDROGEN_FUEL_BLOCK_TYPE_ID = 4246;
    private const int PROCESS_DECRYPTOR = 34205;
    private const int UNKNOWN_DECRYPTOR = 34299;

    public function testWithoutQueryAnalyzesOneRunAtMeTenAndTeTwentyWithoutDecryptor(): void
    {
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                1,
                10,
                20,
                $this->anything(),
                $this->anything(),
                null,
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->analysis());

        $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public function testWithoutQueryNorSettingsBuildsInJitaAndSellsAtJitaStationAtDefaultRates(): void
    {
        $user = new User();
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                $this->anything(),
                $this->anything(),
                $this->anything(),
                EveConstants::JITA_STATION_ID,
                EveConstants::JITA_SOLAR_SYSTEM_ID,
                $this->anything(),
                0.036,
                0.036,
                $user,
                EveConstants::JITA_SOLAR_SYSTEM_ID,
            )
            ->willReturn($this->analysis());

        $this->provider($user, new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public function testAnalyzedRunsOutputQuantityAndTotalCostReachTheResource(): void
    {
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis());

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(self::HAIL_L_TYPE_ID, $resource->typeId);
        $this->assertSame(10, $resource->runs);
        $this->assertSame(50000, $resource->outputQuantity);
        $this->assertSame(526232.3, $resource->totalCost);
        $this->assertNull($resource->margins['jitaSell']);
    }

    public function testUnknownCostsReachTheResourceWithTheirMissingData(): void
    {
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn([
            'materialCost' => null,
            'jobInstallCost' => null,
            'inventionCost' => null,
            'totalCost' => null,
            'costPerUnit' => null,
            'missingData' => [['reason' => 'market_price', 'typeId' => 34], ['reason' => 'cost_index', 'typeId' => null]],
        ] + $this->analysis());

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertNull($resource->materialCost);
        $this->assertNull($resource->jobInstallCost);
        $this->assertNull($resource->inventionCost);
        $this->assertNull($resource->totalCost);
        $this->assertNull($resource->costPerUnit);
        $this->assertSame([['reason' => 'market_price', 'typeId' => 34], ['reason' => 'cost_index', 'typeId' => null]], $resource->missingData);
    }

    public function testDecryptorUnknownToTheSdeIsABadRequest(): void
    {
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->never())->method('analyze');

        $this->expectException(BadRequestHttpException::class);

        $this->provider(new User(), new Request(['decryptorTypeId' => (string) self::UNKNOWN_DECRYPTOR]), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public function testRunsBelowOneAreRaisedToOneRun(): void
    {
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(self::HAIL_L_TYPE_ID, 1, 2, 4, 1035466617946, 30002510, self::PROCESS_DECRYPTOR)
            ->willReturn($this->analysis());

        $request = new Request([
            'runs' => '0',
            'me' => '2',
            'te' => '4',
            'structureId' => '1035466617946',
            'solarSystemId' => '30002510',
            'decryptorTypeId' => (string) self::PROCESS_DECRYPTOR,
        ]);

        $this->provider(new User(), $request, $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public function testUserSettingsAndPreferredMarketStructureReplaceTheDefaults(): void
    {
        $user = (new User())->setPreferredMarketStructureId(self::PREFERRED_MARKET_STRUCTURE_ID);
        $settings = $this->settings($user);
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                1,
                10,
                20,
                self::PREFERRED_MARKET_STRUCTURE_ID,
                self::FAVORITE_MANUFACTURING_SYSTEM_ID,
                null,
                0.015,
                0.0225,
                $user,
                self::FAVORITE_MANUFACTURING_SYSTEM_ID,
            )
            ->willReturn($this->analysis());

        $this->provider($user, new Request(), $profitMarginService, $settings)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    public function testQueryStructureAndSystemTakePrecedenceOverSettingsAndPreferredMarketStructure(): void
    {
        $user = (new User())->setPreferredMarketStructureId(self::PREFERRED_MARKET_STRUCTURE_ID);
        $settings = $this->settings($user);
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                3,
                10,
                20,
                1046664001931,
                EveConstants::JITA_SOLAR_SYSTEM_ID,
                null,
                0.015,
                0.0225,
                $user,
                EveConstants::JITA_SOLAR_SYSTEM_ID,
            )
            ->willReturn($this->analysis());

        $request = new Request([
            'runs' => '3',
            'structureId' => '1046664001931',
            'solarSystemId' => (string) EveConstants::JITA_SOLAR_SYSTEM_ID,
        ]);

        $this->provider($user, $request, $profitMarginService, $settings)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    #[Group('issue-128')]
    public function testFavoriteReactionSystemReachesTheServiceBesideTheFavoriteManufacturingSystem(): void
    {
        $user = new User();
        $settings = $this->settings($user)->setFavoriteReactionSystemId(self::FAVORITE_REACTION_SYSTEM_ID);
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                1,
                10,
                20,
                EveConstants::JITA_STATION_ID,
                self::FAVORITE_MANUFACTURING_SYSTEM_ID,
                null,
                0.015,
                0.0225,
                $user,
                self::FAVORITE_REACTION_SYSTEM_ID,
            )
            ->willReturn($this->analysis());

        $this->provider($user, new Request(), $profitMarginService, $settings)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    #[Group('issue-128')]
    public function testQuerySystemAppliesToManufacturingAndReactionWhateverTheFavoriteSystems(): void
    {
        $user = new User();
        $settings = $this->settings($user)->setFavoriteReactionSystemId(self::FAVORITE_REACTION_SYSTEM_ID);
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                1,
                10,
                20,
                EveConstants::JITA_STATION_ID,
                self::REQUESTED_SYSTEM_ID,
                null,
                0.015,
                0.0225,
                $user,
                self::REQUESTED_SYSTEM_ID,
            )
            ->willReturn($this->analysis());

        $this->provider($user, new Request(['solarSystemId' => (string) self::REQUESTED_SYSTEM_ID]), $profitMarginService, $settings)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);
    }

    #[Group('issue-108')]
    public function testUnknownDailyVolumeAndMarginsReachTheResource(): void
    {
        $unknownMargin = ['revenue' => 700000.0, 'fees' => 50400.0, 'profit' => 649600.0, 'margin' => null];
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis([
            'invention' => ['options' => [['decryptorTypeId' => null, 'bestMargin' => null], ['decryptorTypeId' => 34205, 'bestMargin' => -1494.6]]],
            'margins' => ['jitaSell' => $unknownMargin, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null],
            'dailyVolume' => null,
        ]));

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertNull($resource->dailyVolume);
        $this->assertSame([null, -1494.6], array_column($resource->invention['options'] ?? [], 'bestMargin'));
        $this->assertSame($unknownMargin, $resource->margins['jitaSell']);
    }

    #[Group('issue-115')]
    public function testProductMissingFromTheSdeReachesTheResourceWithAnUnknownTypeNameAndItsTypeId(): void
    {
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis([
            'typeId' => self::ROBOTICS_TYPE_ID,
            'typeName' => null,
            'isT2' => false,
            'runs' => 3,
            'outputQuantity' => 3,
            'outputPerRun' => 1,
            'totalCost' => 214350.75,
        ]));

        $resource = $this->provider(new User(), new Request(['runs' => '3']), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::ROBOTICS_TYPE_ID]);

        $this->assertNull($resource->typeName);
        $this->assertSame(self::ROBOTICS_TYPE_ID, $resource->typeId);
        $this->assertSame(3, $resource->runs);
        $this->assertSame(3, $resource->outputQuantity);
        $this->assertSame(214350.75, $resource->totalCost);
    }

    #[Group('issue-8')]
    public function testUnknownMaterialCostAndItsMissingMarketPricesReachTheResource(): void
    {
        $unpricedRobotics = ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => 'Robotics', 'quantity' => 14.0, 'unitPrice' => null, 'totalPrice' => null];
        $pricedMorphite = ['typeId' => self::MORPHITE_TYPE_ID, 'typeName' => 'Morphite', 'quantity' => 140.0, 'unitPrice' => 9120.5, 'totalPrice' => 1276870.0];
        $processOption = ['decryptorTypeId' => self::PROCESS_DECRYPTOR, 'inventionCost' => 12805.74, 'totalProductionCost' => null, 'bestMargin' => null];
        $missingMarketPrices = [
            ['reason' => 'market_price', 'typeId' => self::ROBOTICS_TYPE_ID],
            ['reason' => 'market_price', 'typeId' => self::NITROGEN_ISOTOPES_TYPE_ID],
        ];
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis([
            'materialCost' => null,
            'materials' => [$unpricedRobotics, $pricedMorphite],
            'inventionCost' => 12805.74,
            'totalCost' => null,
            'costPerUnit' => null,
            'invention' => ['options' => [$processOption]],
            'missingData' => $missingMarketPrices,
        ]));

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertNull($resource->materialCost);
        $this->assertSame([$unpricedRobotics, $pricedMorphite], $resource->materials);
        $this->assertSame(15279.0, $resource->jobInstallCost);
        $this->assertSame(12805.74, $resource->inventionCost);
        $this->assertNull($resource->totalCost);
        $this->assertNull($resource->costPerUnit);
        $this->assertSame([$processOption], $resource->invention['options'] ?? []);
        $this->assertSame($missingMarketPrices, $resource->missingData);
    }

    #[Group('issue-7')]
    #[Group('issue-8')]
    public function testFullyFiguredAnalysisReachesTheResourceWithoutMissingData(): void
    {
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis());

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(508100.0, $resource->materialCost);
        $this->assertSame(15279.0, $resource->jobInstallCost);
        $this->assertSame([], $resource->missingData);
    }

    #[Group('issue-7')]
    public function testUnknownJobInstallCostStepsAndTheirMissingDataReachTheResource(): void
    {
        $unknownFerniteCarbideReaction = [
            'productTypeId' => self::FERNITE_CARBIDE_TYPE_ID,
            'productName' => 'Fernite Carbide',
            'activityType' => 'reaction',
            'runs' => 3.0,
            'installCost' => null,
            'missingData' => [['reason' => 'cost_index', 'typeId' => null]],
        ];
        $figuredHydrogenFuelBlockJob = [
            'productTypeId' => self::HYDROGEN_FUEL_BLOCK_TYPE_ID,
            'productName' => 'Hydrogen Fuel Block',
            'activityType' => 'manufacturing',
            'runs' => 2.0,
            'installCost' => 1873.47974,
            'missingData' => [],
        ];
        $processOption = ['decryptorTypeId' => self::PROCESS_DECRYPTOR, 'inventionCost' => 12805.74, 'totalProductionCost' => null, 'bestMargin' => null];
        $missingInstallCostData = [
            ['reason' => 'cost_index', 'typeId' => null],
            ['reason' => 'adjusted_price', 'typeId' => self::ROBOTICS_TYPE_ID],
        ];
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis([
            'jobInstallCost' => null,
            'jobInstallSteps' => [$unknownFerniteCarbideReaction, $figuredHydrogenFuelBlockJob],
            'inventionCost' => 12805.74,
            'totalCost' => null,
            'costPerUnit' => null,
            'invention' => ['options' => [$processOption]],
            'missingData' => $missingInstallCostData,
        ]));

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertNull($resource->jobInstallCost);
        $this->assertSame([$unknownFerniteCarbideReaction, $figuredHydrogenFuelBlockJob], $resource->jobInstallSteps);
        $this->assertSame(508100.0, $resource->materialCost);
        $this->assertSame(12805.74, $resource->inventionCost);
        $this->assertNull($resource->totalCost);
        $this->assertNull($resource->costPerUnit);
        $this->assertSame([$processOption], $resource->invention['options'] ?? []);
        $this->assertSame($missingInstallCostData, $resource->missingData);
    }

    public function testPartialPriceWarningsReachTheResourceAsTheServiceListsThem(): void
    {
        $partialPriceWarnings = [
            ['reason' => 'partial_market_depth', 'typeId' => self::MORPHITE_TYPE_ID, 'coveragePercent' => 30.0],
            ['reason' => 'partial_market_depth', 'typeId' => self::ROBOTICS_TYPE_ID, 'coveragePercent' => 99.9],
        ];
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis(['warnings' => $partialPriceWarnings]));

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(526232.3, $resource->totalCost);
        $this->assertSame([], $resource->missingData);
        $this->assertSame($partialPriceWarnings, $resource->warnings);
    }

    public function testAnalysisWithoutPartialPriceReachesTheResourceWithoutWarnings(): void
    {
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis());

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame([], $resource->warnings);
    }

    private function settings(User $user): IndustryUserSettings
    {
        return (new IndustryUserSettings())
            ->setUser($user)
            ->setBrokerFeeRate(0.015)
            ->setSalesTaxRate(0.0225)
            ->setFavoriteManufacturingSystemId(self::FAVORITE_MANUFACTURING_SYSTEM_ID);
    }

    private function provider(User $user, Request $request, ProfitMarginService $profitMarginService, ?IndustryUserSettings $settings = null): ProfitMarginProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn($settings);

        $decryptors = $this->createStub(Decryptors::class);
        $decryptors->method('all')->willReturn([new Decryptor(self::PROCESS_DECRYPTOR, new Multiplier(1.1), 0, 3, 6)]);

        return new ProfitMarginProvider($security, $requestStack, $profitMarginService, $settingsRepository, $decryptors);
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(array $overrides = []): array
    {
        return array_replace([
            'typeId' => self::HAIL_L_TYPE_ID,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => 10,
            'outputQuantity' => 50000,
            'outputPerRun' => 5000,
            'materialCost' => 508100.0,
            'materials' => [],
            'jobInstallCost' => 15279.0,
            'jobInstallSteps' => [],
            'inventionCost' => 2685.0,
            'totalCost' => 526232.3,
            'costPerUnit' => 10.524646,
            'invention' => null,
            'sellPrices' => [],
            'brokerFeeRate' => 0.036,
            'salesTaxRate' => 0.036,
            'margins' => ['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null],
            'dailyVolume' => 0.0,
            'missingData' => [],
            'warnings' => [],
        ], $overrides);
    }
}
