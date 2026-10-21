<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Entity\User;
use App\Repository\IndustryUserSettingsRepository;
use App\Service\Industry\ProfitMarginService;
use App\State\Provider\Industry\ProfitMarginProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Characterization of the /industry/profit-margin/{typeId} defaults, before ProfitMarginService moves to the industry engine (spec §7, rank 1).
 * The computation itself is covered by ProfitMarginServiceTest and ProfitMarginGoldenCharacterizationTest.
 */
#[CoversClass(ProfitMarginProvider::class)]
class ProfitMarginProviderTest extends TestCase
{
    private const int HAIL_L_TYPE_ID = 12779;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1035466617946;
    private const int FAVORITE_MANUFACTURING_SYSTEM_ID = 30002510;
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int NITROGEN_ISOTOPES_TYPE_ID = 17888;

    public function testWithoutQueryNorSettingsCurrentlyAnalyzesOneRunAtMeTenInJitaSoldAtJitaStation(): void
    {
        $user = new User();
        $profitMarginService = $this->createMock(ProfitMarginService::class);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #18 (ME 10 by default, even for a T2 whose invented BPC is ME 2)
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. glossaire « Structure de marché par défaut » (the Jita station id is sent as a structure id)
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(
                self::HAIL_L_TYPE_ID,
                1,
                10,
                20,
                EveConstants::JITA_STATION_ID,
                EveConstants::JITA_SOLAR_SYSTEM_ID,
                null,
                0.036,
                0.036,
                $user,
            )
            ->willReturn($this->analysis());

        $resource = $this->provider($user, new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(self::HAIL_L_TYPE_ID, $resource->typeId);
        $this->assertSame(10, $resource->runs);
        $this->assertSame(50000, $resource->outputQuantity);
        $this->assertSame(526232.3, $resource->totalCost);
        $this->assertNull($resource->margins['jitaSell']);
    }

    public function testRunsBelowOneAreCurrentlyRaisedToOneRun(): void
    {
        $profitMarginService = $this->createMock(ProfitMarginService::class);
        $profitMarginService->expects($this->once())
            ->method('analyze')
            ->with(self::HAIL_L_TYPE_ID, 1, 2, 4, 1035466617946, 30002510, 34205)
            ->willReturn($this->analysis());

        $request = new Request([
            'runs' => '0',
            'me' => '2',
            'te' => '4',
            'structureId' => '1035466617946',
            'solarSystemId' => '30002510',
            'decryptorTypeId' => '34205',
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
    public function testUnknownMaterialCostAndMissingPriceTypeIdsReachTheResource(): void
    {
        $unpricedRobotics = ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => 'Robotics', 'quantity' => 14, 'unitPrice' => null, 'totalPrice' => null];
        $pricedMorphite = ['typeId' => self::MORPHITE_TYPE_ID, 'typeName' => 'Morphite', 'quantity' => 140, 'unitPrice' => 9120.5, 'totalPrice' => 1276870.0];
        $processOption = ['decryptorTypeId' => 34205, 'inventionCost' => 12805.74, 'totalProductionCost' => null, 'bestMargin' => null];
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis([
            'materialCost' => null,
            'materials' => [$unpricedRobotics, $pricedMorphite],
            'inventionCost' => 12805.74,
            'totalCost' => null,
            'costPerUnit' => null,
            'invention' => ['options' => [$processOption]],
            'missingPriceTypeIds' => [self::ROBOTICS_TYPE_ID, self::NITROGEN_ISOTOPES_TYPE_ID],
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
        $this->assertSame([self::ROBOTICS_TYPE_ID, self::NITROGEN_ISOTOPES_TYPE_ID], $resource->missingPriceTypeIds);
    }

    #[Group('issue-8')]
    public function testFullyPricedAnalysisReachesTheResourceWithoutMissingPriceTypeIds(): void
    {
        $profitMarginService = $this->createStub(ProfitMarginService::class);
        $profitMarginService->method('analyze')->willReturn($this->analysis());

        $resource = $this->provider(new User(), new Request(), $profitMarginService)
            ->provide(new Get(), ['typeId' => (string) self::HAIL_L_TYPE_ID]);

        $this->assertSame(508100.0, $resource->materialCost);
        $this->assertSame([], $resource->missingPriceTypeIds);
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

        return new ProfitMarginProvider($security, $requestStack, $profitMarginService, $settingsRepository);
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
            'copyCost' => 168.3,
            'totalCost' => 526232.3,
            'costPerUnit' => 10.524646,
            'invention' => null,
            'sellPrices' => [],
            'brokerFeeRate' => 0.036,
            'salesTaxRate' => 0.036,
            'margins' => ['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null],
            'dailyVolume' => 0.0,
            'missingPriceTypeIds' => [],
        ], $overrides);
    }
}
