<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Mining;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Mining\OreValueTableResource;
use App\Entity\User;
use App\State\Provider\Mining\OreValueTableProvider;
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

    public function testSellIsTheDefaultSideAndAMissingReprocessYieldLeavesTheReprocessedValuesUnknown(): void
    {
        $table = $this->provideFor(['market' => 'jita']);

        $this->assertSame(
            ['jita', 'sell', null, false],
            [$table->market, $table->side, $table->structureId, $table->reprocessYieldKnown],
        );
        $this->assertSame([self::FULLERITE_C50, self::VELDSPAR], array_column($table->rows, 'typeId'));
        $this->assertEqualsWithDelta(2741.0, $table->rows[0]['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(196.1, $table->rows[1]['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertNull($table->rows[1]['reprocessedIskPerM3']);
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
    }

    public function testAnAnonymousVisitorIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['market' => 'jita']));

        $this->expectException(UnauthorizedHttpException::class);

        (new OreValueTableProvider($security, $requestStack, $this->createOreValueTableService([], [])))->provide(new Get());
    }

    public function testAProvideCallOutsideAnHttpRequestIsABadRequest(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $this->expectException(BadRequestHttpException::class);

        (new OreValueTableProvider($security, new RequestStack(), $this->createOreValueTableService([], [])))->provide(new Get());
    }

    private function provideFor(array $query): OreValueTableResource
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

        return (new OreValueTableProvider($security, $requestStack, $oreValueTableService))->provide(new Get());
    }
}
