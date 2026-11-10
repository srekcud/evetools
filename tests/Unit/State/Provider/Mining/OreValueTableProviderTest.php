<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Mining\OreValueTableResource;
use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\ReprocessingProfileRepository;
use App\State\Provider\Mining\OreValueTableProvider;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\Tests\Unit\Service\Mining\BuildsMiningProfileUnitPrices;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(OreValueTableProvider::class)]
#[Group('issue-268')]
final class OreValueTableProviderTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;
    use BuildsMiningProfileUnitPrices;

    private const int MARKET_STRUCTURE_ID = 1_000_000_000_001;
    private const float VALUE_DELTA = 1e-9;

    public function testJitaBuyTableWithAReprocessYieldValuesEveryOptionAtTheBestJitaBuyOrders(): void
    {
        $table = $this->provideFor(['market' => 'jita', 'side' => 'buy', 'reprocessYield' => '0.78']);

        $this->assertSame(
            ['jita', 'buy', null, true],
            [$table->market, $table->side, $table->structureId, $table->reprocessYieldKnown],
        );
        $this->assertSame([self::VELDSPAR, self::FULLERITE_C50], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(175.0, $table->rows[0]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(180.0, $table->rows[0]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(124.8, $table->rows[0]['reprocessedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(26.9, $table->rows[1]['rawIskPerM3'], self::VALUE_DELTA);
    }

    #[Group('issue-532')]
    public function testWithoutAnyParameterTheTableUsesTheDefaultProfileAndTheYieldOfTheReprocessingProfile(): void
    {
        $table = $this->provideFor([]);

        $this->assertSame(
            ['jita', 'buy', null, true, ['market' => 'jita', 'side' => 'buy', 'form' => 'compressed', 'structureName' => null]],
            [$table->market, $table->side, $table->structureId, $table->reprocessYieldKnown, $table->valuationSource],
        );
        $this->assertSame([self::VELDSPAR, self::FULLERITE_C50], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(175.0, $table->rows[0]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(180.0, $table->rows[0]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(124.8, $table->rows[0]['reprocessedIskPerM3'], self::VALUE_DELTA);
    }

    #[Group('issue-532')]
    public function testWithoutReprocessingProfileTheReprocessedValuesStayUnknown(): void
    {
        $table = $this->provideFor(['market' => 'jita'], null, $this->profileRepositoryWithNoProfile());

        $this->assertSame(
            ['jita', 'buy', null, false],
            [$table->market, $table->side, $table->structureId, $table->reprocessYieldKnown],
        );
        $this->assertSame([self::VELDSPAR, self::FULLERITE_C50], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(180.0, $table->rows[0]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertNull($table->rows[0]['reprocessedIskPerM3']);
    }

    #[Group('issue-532')]
    public function testTheStructureSellRawProfileFillsMarketSideStructureAndSourceWhenTheQueryGivesNone(): void
    {
        $profile = $this->miningValuationProfileOf(
            MiningValuationMarket::Structure,
            MarketSide::Sell,
            MiningValuationForm::Raw,
            self::MARKET_STRUCTURE_ID,
            'Refinery B-1',
        );

        $table = $this->provideFor([], $profile);

        $this->assertSame(
            ['structure', 'sell', self::MARKET_STRUCTURE_ID, ['market' => 'structure', 'side' => 'sell', 'form' => 'raw', 'structureName' => 'Refinery B-1']],
            [$table->market, $table->side, $table->structureId, $table->valuationSource],
        );
        $this->assertSame([self::FULLERITE_C50, self::VELDSPAR], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(2690.0, $table->rows[0]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(192.0, $table->rows[1]['rawIskPerM3'], self::VALUE_DELTA);
    }

    #[Group('issue-532')]
    public function testTheExportTaxOfTheProfileLowersTheJitaIskPerCubicMetre(): void
    {
        $table = $this->provideFor(['market' => 'jita'], $this->miningValuationProfileOf(exportTax: 100.0));

        $this->assertEqualsWithDelta(75.0, $table->rows[0]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(179.0, $table->rows[0]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(124.8, $table->rows[0]['reprocessedIskPerM3'], self::VALUE_DELTA);
    }

    #[Group('issue-532')]
    public function testExplicitParametersTakePriorityOverTheProfile(): void
    {
        $profile = $this->miningValuationProfileOf(
            MiningValuationMarket::Structure,
            MarketSide::Sell,
            MiningValuationForm::Raw,
            self::MARKET_STRUCTURE_ID,
            'Refinery B-1',
            100.0,
        );

        $table = $this->provideFor(['market' => 'jita', 'side' => 'buy', 'reprocessYield' => '0.5', 'exportTax' => '0'], $profile);

        $this->assertSame(['jita', 'buy', null, true], [$table->market, $table->side, $table->structureId, $table->reprocessYieldKnown]);
        $this->assertSame([self::VELDSPAR, self::FULLERITE_C50], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(180.0, $table->rows[0]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(80.0, $table->rows[0]['reprocessedIskPerM3'], self::VALUE_DELTA);
    }

    #[Group('issue-532')]
    public function testAnExplicitExportTaxOverridesTheTaxOfTheProfile(): void
    {
        $table = $this->provideFor(['market' => 'jita', 'exportTax' => '100'], $this->miningValuationProfileOf(exportTax: 0.0));

        $this->assertEqualsWithDelta(179.0, $table->rows[0]['compressedIskPerM3'], self::VALUE_DELTA);
    }

    public function testStructureTableReadsTheStructureIdAndTheStructureBuyOrders(): void
    {
        $table = $this->provideFor([
            'market' => 'structure',
            'side' => 'buy',
            'structureId' => (string) self::MARKET_STRUCTURE_ID,
            'reprocessYield' => '1',
        ]);

        $this->assertSame(
            ['structure', 'buy', self::MARKET_STRUCTURE_ID, true],
            [$table->market, $table->side, $table->structureId, $table->reprocessYieldKnown],
        );
        $this->assertSame([self::FULLERITE_C50, self::VELDSPAR], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(2605.0, $table->rows[0]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(169.0, $table->rows[1]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(172.0, $table->rows[1]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(154.0, $table->rows[1]['reprocessedIskPerM3'], self::VALUE_DELTA);
    }

    #[DataProvider('invalidQueries')]
    public function testAnInvalidQueryIsABadRequest(array $query): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->provideFor($query);
    }

    public static function invalidQueries(): iterable
    {
        yield 'structure market without structureId' => [['market' => 'structure', 'side' => 'sell']];
        yield 'structureId of zero' => [['market' => 'structure', 'side' => 'sell', 'structureId' => '0']];
        yield 'negative structureId' => [['market' => 'structure', 'side' => 'sell', 'structureId' => '-1022734985679']];
        yield 'unknown market' => [['market' => 'foo', 'side' => 'sell']];
        yield 'unknown side' => [['market' => 'jita', 'side' => 'foo']];
        yield 'reprocess yield above one' => [['market' => 'jita', 'reprocessYield' => '1.5']];
        yield 'reprocess yield of zero' => [['market' => 'jita', 'reprocessYield' => '0']];
        yield 'negative reprocess yield' => [['market' => 'jita', 'reprocessYield' => '-0.1']];
        yield 'reprocess yield that is not a number' => [['market' => 'jita', 'reprocessYield' => 'abc']];
        yield 'negative export tax' => [['market' => 'jita', 'exportTax' => '-1']];
        yield 'export tax that is not a number' => [['market' => 'jita', 'exportTax' => 'abc']];
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['market' => 'jita']));

        $this->expectException(UnauthorizedHttpException::class);

        (new OreValueTableProvider($security, $requestStack, $this->createOreValueTableService([], []), $this->profileReader(null), $this->profileRepositoryWithNoProfile()))->provide(new Get());
    }

    public function testAProvideCallOutsideAnHttpRequestIsABadRequest(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $this->expectException(BadRequestHttpException::class);

        (new OreValueTableProvider($security, new RequestStack(), $this->createOreValueTableService([], []), $this->profileReader(null), $this->profileRepositoryWithNoProfile()))->provide(new Get());
    }

    private function profileReader(?MiningValuationProfile $profile): MiningValuationProfileReader
    {
        return new MiningValuationProfileReader($this->miningValuationProfileRepositoryReturning($profile));
    }

    private function provideFor(array $query, ?MiningValuationProfile $profile = null, ?ReprocessingProfileRepository $reprocessingProfileRepository = null): OreValueTableResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));
        $oreValueTableService = $this->createOreValueTableService(
            [
                self::VELDSPAR => 18.37,
                self::COMPRESSED_VELDSPAR => 19.61,
                self::TRITANIUM => 4.27,
                self::FULLERITE_C50 => 2741.0,
            ],
            [self::VELDSPAR, self::FULLERITE_C50],
            [
                self::VELDSPAR => 17.5,
                self::COMPRESSED_VELDSPAR => 18.0,
                self::TRITANIUM => 4.0,
                self::FULLERITE_C50 => 26.9,
            ],
            $this->structureMarketTrading(
                self::MARKET_STRUCTURE_ID,
                [self::VELDSPAR => 19.2, self::COMPRESSED_VELDSPAR => 20.4, self::TRITANIUM => 4.9, self::FULLERITE_C50 => 2690.0],
                [self::VELDSPAR => 16.9, self::COMPRESSED_VELDSPAR => 17.2, self::TRITANIUM => 3.85, self::FULLERITE_C50 => 2605.0],
            ),
        );

        return (new OreValueTableProvider($security, $requestStack, $oreValueTableService, $this->profileReader($profile), $reprocessingProfileRepository ?? $this->profileRepositoryWithYield(0.78)))->provide(new Get());
    }
}
