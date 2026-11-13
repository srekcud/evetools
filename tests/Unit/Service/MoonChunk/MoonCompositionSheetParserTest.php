<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Service\MoonChunk\MoonCompositionSheetParser;
use App\Service\MoonChunk\MoonScanException;
use App\Service\MoonChunk\MoonScanLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonCompositionSheetParser::class)]
#[Group('issue-853')]
final class MoonCompositionSheetParserTest extends TestCase
{
    private MoonCompositionSheetParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MoonCompositionSheetParser();
    }

    public function testTheMoonNameAndTheOreCompositionAreReadByHeaderEvenWhenOtherCellsHoldQuotedCommas(): void
    {
        $csv = "Moon Name,Region,Ore Composition,Max Value\nVeyra - II - Moon 1,Northreach,Bitumens: 0.5;Chromite: 0.3,\"4,696,153,799.0\"\nVeyra - II - Moon 2,Northreach,Zeolites: 1.0,\"1,000.0\"\n";

        self::assertSame(
            ['Veyra - II - Moon 1' => 'Bitumens: 0.5;Chromite: 0.3', 'Veyra - II - Moon 2' => 'Zeolites: 1.0'],
            $this->parser->compositionsByMoonName($csv),
        );
    }

    public function testTheColumnsMayComeInAnyOrderWithWindowsLineEndingsAndBlankLines(): void
    {
        $csv = "Ore Composition,Moon Name\r\nBitumens: 0.5,Veyra - II - Moon 1\r\n\r\nZeolites: 0.4,Veyra - II - Moon 2\r\n";

        self::assertSame(
            ['Veyra - II - Moon 1' => 'Bitumens: 0.5', 'Veyra - II - Moon 2' => 'Zeolites: 0.4'],
            $this->parser->compositionsByMoonName($csv),
        );
    }

    public function testWhenAMoonNameIsListedTwiceTheFirstRowWins(): void
    {
        $csv = "Moon Name,Ore Composition\nVeyra - II - Moon 1,Bitumens: 0.5\nVeyra - II - Moon 1,Zeolites: 0.9\n";

        self::assertSame(['Veyra - II - Moon 1' => 'Bitumens: 0.5'], $this->parser->compositionsByMoonName($csv));
    }

    #[DataProvider('provideCsvWithoutTheExpectedColumns')]
    public function testASheetWithoutTheMoonNameOrOreCompositionColumnIsRejected(string $csv): void
    {
        $this->expectException(MoonScanException::class);

        $this->parser->compositionsByMoonName($csv);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCsvWithoutTheExpectedColumns(): iterable
    {
        yield 'empty file' => [''];
        yield 'no ore composition column' => ["Moon Name,Region\nVeyra - II - Moon 1,Northreach\n"];
        yield 'no moon name column' => ["Name,Ore Composition\nVeyra - II - Moon 1,Bitumens: 0.5\n"];
    }

    public function testACompositionGivesOneLineByOreWithTheShareAsWritten(): void
    {
        self::assertEquals(
            [new MoonScanLine('Euxenite', 0.2329, 1), new MoonScanLine('Zeolites', 0.7671, 2)],
            $this->parser->lines('Euxenite: 0.2329;Zeolites: 0.7671'),
        );
    }

    public function testASumOfSharesBelowOneIsAcceptedAndNotRescaled(): void
    {
        self::assertEquals(
            [new MoonScanLine('Bitumens', 0.5, 1), new MoonScanLine('Chromite', 0.3, 2)],
            $this->parser->lines('Bitumens: 0.5; Chromite : 0.3'),
        );
    }

    #[DataProvider('provideRejectedCompositions')]
    public function testAMalformedCompositionIsRejected(string $composition): void
    {
        $this->expectException(MoonScanException::class);

        $this->parser->lines($composition);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedCompositions(): iterable
    {
        yield 'empty' => [''];
        yield 'no share' => ['Bitumens'];
        yield 'share not a number' => ['Bitumens: abc'];
        yield 'share of zero' => ['Bitumens: 0'];
        yield 'negative share' => ['Bitumens: -0.1;Chromite: 0.5'];
        yield 'share above one' => ['Bitumens: 1.5'];
        yield 'ore listed twice' => ['Bitumens: 0.5;bitumens: 0.3'];
        yield 'shares adding up above the tolerance' => ['Chromite: 0.9;Zeolites: 0.3'];
        yield 'empty segment' => ['Bitumens: 0.5;;Chromite: 0.3'];
    }
}
