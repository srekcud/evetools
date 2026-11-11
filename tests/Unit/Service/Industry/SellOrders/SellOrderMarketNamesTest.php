<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellOrders;

use App\Constant\EveConstants;
use App\Entity\CachedStructure;
use App\Repository\CachedStructureRepository;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellOrders\SellOrderMarketNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SellOrderMarketNames::class)]
#[Group('issue-350')]
final class SellOrderMarketNamesTest extends TestCase
{
    private const int STRUCTURE_ID = 1_040_000_000_350;

    public function testJitaStationHasTheJitaMarketName(): void
    {
        $names = new SellOrderMarketNames($this->createStub(CachedStructureRepository::class));

        self::assertSame(SellMarketComparison::JITA_MARKET_NAME, $names->of(EveConstants::JITA_STATION_ID));
    }

    public function testKnownStructureHasItsCachedName(): void
    {
        $structure = $this->createStub(CachedStructure::class);
        $structure->method('getName')->willReturn('Perimeter - Capital Trade Hub');
        $repository = $this->createStub(CachedStructureRepository::class);
        $repository->method('findByStructureId')->with(self::STRUCTURE_ID)->willReturn($structure);

        self::assertSame('Perimeter - Capital Trade Hub', (new SellOrderMarketNames($repository))->of(self::STRUCTURE_ID));
    }

    public function testUnknownLocationHasNoNameInsteadOfAGuess(): void
    {
        $repository = $this->createStub(CachedStructureRepository::class);
        $repository->method('findByStructureId')->willReturn(null);

        self::assertNull((new SellOrderMarketNames($repository))->of(60_008_494));
    }
}
