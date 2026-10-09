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
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Characterization of GET /industry/buy-vs-build/{typeId} before the migration of BuyVsBuildService
 * (docs/specs/industry-engine.md §7, rank 2): query parameters, user settings and their defaults.
 *
 * The provider runs on the real BuyVsBuildService over the SDE extract of the goldens. Each analysis
 * parameter changes the output (cost index per system, sell prices per structure, ME on quantities),
 * so the resource is compared with a direct analysis made with the expected parameters.
 */
#[CoversClass(BuyVsBuildProvider::class)]
final class BuyVsBuildProviderTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const int HAIL_L = 12779;
    private const int FERNITE_CARBIDE = 16673;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int HYDROGEN_FUEL_BLOCK = 4246;

    public function testWithoutParametersNorSettingsAnalyzesOneRunAtMe10InJitaWithDefaultFees(): void
    {
        $resource = $this->provide(self::HAIL_L, [], new User(), null);

        $this->assertSame(1, $resource->runs);
        $this->assertSame(2700, $this->componentOf($resource, self::FERNITE_CARBIDE)['quantity']);
        $this->assertSame(450.0, $resource->sellPrice);
        $this->assertSame(4720.0, $this->componentOf($resource, self::FERNITE_CARBIDE)['buildJobInstallCost']);
        $this->assertResourceIsTheAnalysisOf(
            $resource,
            self::HAIL_L, 1, 10, EveConstants::JITA_SOLAR_SYSTEM_ID, EveConstants::JITA_STATION_ID,
            EveConstants::DEFAULT_BROKER_FEE_RATE, EveConstants::DEFAULT_SALES_TAX_RATE,
        );
    }

    public function testCurrentlyWrongWithoutPreferredStructureSellsAtTheJitaStationAsIfItWereAStructure(): void
    {
        $resource = $this->provide(self::HAIL_L, [], new User(), null);

        // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue dédiée (glossaire, « Structure de marché
        // par défaut appliquée inégalement »). The Jita station ID is passed as a structure ID to StructureMarketService.
        $this->assertSame(450.0, $resource->sellPrice);
        $this->assertSame(21000.0, $this->componentOf($resource, self::RAM_AMMUNITION_TECH)['buyCostStructure']);
    }

    public function testUserSettingsGiveTheFeesTheFavoriteManufacturingSystemAndThePreferredStructure(): void
    {
        $user = (new User())->setPreferredMarketStructureId(self::SELL_STRUCTURE_ID);
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID);

        $resource = $this->provide(self::HAIL_L, [], $user, $settings);

        $this->assertSame(500.0, $resource->sellPrice);
        $this->assertResourceIsTheAnalysisOf($resource, self::HAIL_L, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.01, 0.02);
    }

    public function testQueryParametersOverrideTheSettingsAndRunsAreAtLeastOne(): void
    {
        $user = (new User())->setPreferredMarketStructureId(self::SELL_STRUCTURE_ID);
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

    public function testRunsQueryParameterIsForwardedAsRuns(): void
    {
        $resource = $this->provide(self::HAIL_L, ['runs' => '10'], new User(), null);

        $this->assertSame(10, $resource->runs);
        $this->assertResourceIsTheAnalysisOf(
            $resource,
            self::HAIL_L, 10, 10, EveConstants::JITA_SOLAR_SYSTEM_ID, EveConstants::JITA_STATION_ID,
            EveConstants::DEFAULT_BROKER_FEE_RATE, EveConstants::DEFAULT_SALES_TAX_RATE,
        );
    }

    public function testReactionProductCurrentlyWrongUsesTheFavoriteManufacturingSystem(): void
    {
        $user = new User();
        $settings = $this->settingsOf($user, favoriteManufacturingSystemId: self::FACTORY_SYSTEM_ID)
            ->setFavoriteReactionSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID);

        $resource = $this->provide(self::FERNITE_CARBIDE, [], $user, $settings);

        // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue dédiée : the favorite reaction system is
        // ignored, the reaction jobs take the reaction cost index of the favorite manufacturing system (0.1, not 0.04).
        $this->assertSame(9000.0, $this->componentOf($resource, 16656)['buildJobInstallCost']);
        $this->assertSame(787.5, $this->componentOf($resource, self::HYDROGEN_FUEL_BLOCK)['buildJobInstallCost']);
        $this->assertResourceIsTheAnalysisOf($resource, self::FERNITE_CARBIDE, 1, 10, self::FACTORY_SYSTEM_ID, EveConstants::JITA_STATION_ID, 0.01, 0.02);
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provide(self::HAIL_L, [], null, null);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /** @param array<string, string> $query */
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
        int $sellStructureId,
        float $brokerFeeRate,
        float $salesTaxRate,
    ): void {
        $analysis = $this->goldenBuyVsBuildService()->analyze($typeId, $runs, $meLevel, $solarSystemId, $sellStructureId, $brokerFeeRate, $salesTaxRate, null);

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
                16656 => 300.0,
                16660 => 250.0,
            ],
            structurePrices: [
                self::SELL_STRUCTURE_ID => [self::RAM_AMMUNITION_TECH => 25000.0],
                EveConstants::JITA_STATION_ID => [self::HAIL_L => 450.0, self::RAM_AMMUNITION_TECH => 21000.0],
            ],
            adjustedPrices: [34 => 4.0, 17889 => 35.0, self::HYDROGEN_FUEL_BLOCK => 18000.0, 16656 => 280.0, self::FERNITE_CARBIDE => 50.0, 11399 => 9000.0],
            costIndices: [
                EveConstants::JITA_SOLAR_SYSTEM_ID => ['manufacturing' => 0.02, 'reaction' => 0.04],
                self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1],
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

    /** @return array<string, mixed> */
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
