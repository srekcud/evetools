<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonChunk;

use App\Service\MoonChunk\MoonScanException;
use App\Service\MoonChunk\MoonScanLine;
use App\Service\MoonChunk\MoonScanParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonScanParser::class)]
#[CoversClass(MoonScanLine::class)]
#[CoversClass(MoonScanException::class)]
#[Group('issue-308')]
final class MoonScanParserTest extends TestCase
{
    private MoonScanParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MoonScanParser();
    }

    public function testATabSeparatedScanGivesOneLineByOreWithTheShareAsAFraction(): void
    {
        self::assertSame(
            [['Bitumens', 0.5, 1], ['Chromite', 0.5, 2]],
            $this->figuresOf("Bitumens\t50 %\nChromite\t0,5"),
        );
    }

    public function testTheShareIsReadAsAFractionOrAPercentageWithACommaOrAPointDecimalSeparator(): void
    {
        self::assertSame(
            [['Bitumens', 0.2581, 1], ['Chromite', 0.3419, 2], ['Zeolites', 0.205, 3], ['Sylvite', 0.199, 4]],
            $this->figuresOf("Bitumens\t0.2581\nChromite\t0,3419\nZeolites\t20,5 %\nSylvite\t19.9%"),
        );
    }

    public function testWindowsLineEndingsAreAccepted(): void
    {
        self::assertSame(
            [['Bitumens', 0.5, 1], ['Chromite', 0.5, 2]],
            $this->figuresOf("Bitumens\t50 %\r\nChromite\t0,5\r\n"),
        );
    }

    public function testTwoOrMoreSpacesSeparateAMultiWordNameFromItsShare(): void
    {
        self::assertSame(
            [['Bright Spodumain', 0.5, 1], ['Bitumens', 0.5, 2]],
            $this->figuresOf("Bright Spodumain  0.5\nBitumens   0.5"),
        );
    }

    public function testBlankLinesAreIgnoredAndTheLineNumbersCountThem(): void
    {
        self::assertSame(
            [['Bitumens', 0.5, 2], ['Chromite', 0.5, 5]],
            $this->figuresOf("\nBitumens\t0.5\n\n  \nChromite\t0.5\n"),
        );
    }

    public function testASumOfSharesInsideTheToleranceIsAccepted(): void
    {
        self::assertSame(
            [0.995, 1.005],
            [$this->sumOfSharesOf("Bitumens\t50 %\nChromite\t49,5 %"), $this->sumOfSharesOf("Bitumens\t50 %\nChromite\t50,5 %")],
        );
    }

    public static function rejectedScans(): iterable
    {
        yield 'ore without share' => ["Bitumens\t0.5\nChromite", 2];
        yield 'share that is not a number' => ["Bitumens\t0.5\nChromite\tlots", 2];
        yield 'share of zero' => ["Bitumens\t0.5\nChromite\t0", 2];
        yield 'negative share' => ["Bitumens\t0.5\nChromite\t-0,2", 2];
        yield 'share above one' => ["Bitumens\t0.5\nChromite\t1,2", 2];
        yield 'ore listed twice' => ["Bitumens\t0.5\nChromite\t0.3\nBitumens\t0.2", 3];
        yield 'ore listed twice with another case' => ["Bitumens\t0.5\nChromite\t0.3\nbitumens\t0.2", 3];
        yield 'no line' => ['', null];
        yield 'only blank lines' => ["\n  \n\r\n", null];
        yield 'sum of shares of one half' => ["bitumens  0.25\nChromite  0.25", null];
        yield 'sum of shares just under the tolerance' => ["Bitumens\t50 %\nChromite\t48 %", null];
        yield 'sum of shares just above the tolerance' => ["Bitumens\t50 %\nChromite\t52 %", null];
    }

    #[DataProvider('rejectedScans')]
    public function testAScanIsRejectedWithTheNumberOfTheFaultyLine(string $scan, ?int $faultyLineNumber): void
    {
        try {
            $this->parser->parse($scan);
            self::fail('The scan should have been rejected');
        } catch (MoonScanException $rejection) {
            self::assertSame($faultyLineNumber, $rejection->lineNumber);
            if ($faultyLineNumber !== null) {
                self::assertStringContainsString('line '.$faultyLineNumber, $rejection->getMessage());
            }
        }
    }

    private function figuresOf(string $scan): array
    {
        return array_map(
            static fn (MoonScanLine $line): array => [$line->name, round($line->share, 6), $line->lineNumber],
            $this->parser->parse($scan),
        );
    }

    private function sumOfSharesOf(string $scan): float
    {
        return round(array_sum(array_map(static fn (MoonScanLine $line): float => $line->share, $this->parser->parse($scan))), 6);
    }
}
