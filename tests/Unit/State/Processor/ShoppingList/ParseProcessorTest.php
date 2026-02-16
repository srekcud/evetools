<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\ShoppingList;

use App\Service\ItemParserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the parse logic in ItemParserService.
 *
 * Uses reflection to test parseLine() directly (private method),
 * and calls parseItemList() publicly, since they contain non-trivial
 * text parsing logic that benefits from thorough unit coverage.
 */
#[CoversClass(ItemParserService::class)]
class ParseProcessorTest extends TestCase
{
    private ItemParserService $parser;

    protected function setUp(): void
    {
        // The service has dependencies we don't need for parsing tests.
        // We create a partial instance using reflection to skip the constructor.
        $reflection = new \ReflectionClass(ItemParserService::class);
        $this->parser = $reflection->newInstanceWithoutConstructor();
    }

    // ===========================================
    // parseLine() single line parsing
    // ===========================================

    #[DataProvider('parseLineProvider')]
    public function testParseLine(string $line, ?string $expectedName, ?int $expectedQuantity): void
    {
        $result = $this->invokeParseLine($line);

        if ($expectedName === null) {
            $this->assertNull($result, "Expected null for line: {$line}");

            return;
        }

        $this->assertNotNull($result, "Expected result for line: {$line}");
        $this->assertSame($expectedName, $result['name'], "Name mismatch for line: {$line}");
        $this->assertSame($expectedQuantity, $result['quantity'], "Quantity mismatch for line: {$line}");
    }

    /**
     * @return iterable<string, array{line: string, expectedName: ?string, expectedQuantity: ?int}>
     */
    public static function parseLineProvider(): iterable
    {
        // Format: "Qty x ItemName"
        yield 'quantity prefix with x' => ['200x Nocxium', 'Nocxium', 200];
        yield 'quantity prefix with x and space' => ['200 x Carbon Fiber', 'Carbon Fiber', 200];
        yield 'quantity prefix with commas' => ['1,000,000x Tritanium', 'Tritanium', 1000000];
        yield 'quantity prefix with X uppercase' => ['50X Megacyte', 'Megacyte', 50];

        // Format: "ItemName x Qty"
        yield 'quantity suffix with x' => ['Megacyte x 500', 'Megacyte', 500];
        yield 'quantity suffix x no space' => ['Pyerite x100', 'Pyerite', 100];
        yield 'quantity suffix with commas' => ['Tritanium x 1,000', 'Tritanium', 1000];

        // Format: tab-separated (EVE clipboard)
        yield 'tab separated' => ["Tritanium\t10000", 'Tritanium', 10000];
        yield 'tab separated with commas' => ["Tritanium\t10,000,000", 'Tritanium', 10000000];
        yield 'multiple tabs' => ["Capital Parts\t\t50", 'Capital Parts', 50];

        // Format: multiple spaces before number
        yield 'multiple spaces before number' => ['Capital Construction Parts  50', 'Capital Construction Parts', 50];

        // Format: "ItemName Qty" (single space)
        yield 'single space and number' => ['Isogen 5000', 'Isogen', 5000];
        yield 'multi-word item name' => ['Capital Construction Parts 25', 'Capital Construction Parts', 25];

        // Format: name only (qty = 1)
        yield 'name only defaults to 1' => ['Tritanium', 'Tritanium', 1];
        yield 'multi-word name only' => ['Capital Shield Booster II', 'Capital Shield Booster II', 1];

        // Bullet points and list prefixes
        yield 'dash prefix' => ['- Tritanium 1000', 'Tritanium', 1000];
        yield 'asterisk prefix' => ['* Nocxium 200', 'Nocxium', 200];

        // Edge cases
        yield 'quantity with commas in suffix' => ['Mexallon 1,500', 'Mexallon', 1500];

        // Lines that should not parse
        yield 'purely numeric line' => ['12345', null, null];
        yield 'empty string' => ['', null, null];
    }

    // ===========================================
    // parseItemList() multi-line parsing
    // ===========================================

    public function testParseItemListBasicMultiline(): void
    {
        $text = "Tritanium 1000\nPyerite 500\nMexallon 200";

        $result = $this->parser->parseItemList($text);

        $this->assertCount(3, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(1000, $result[0]['quantity']);
        $this->assertSame('Pyerite', $result[1]['name']);
        $this->assertSame(500, $result[1]['quantity']);
        $this->assertSame('Mexallon', $result[2]['name']);
        $this->assertSame(200, $result[2]['quantity']);
    }

    public function testParseItemListMergesDuplicateNames(): void
    {
        $text = "Tritanium 1000\nPyerite 500\nTritanium 2000";

        $result = $this->parser->parseItemList($text);

        $this->assertCount(2, $result);
        // Tritanium should be merged: 1000 + 2000 = 3000
        $tritanium = array_filter($result, fn ($item) => $item['name'] === 'Tritanium');
        $tritanium = array_values($tritanium);
        $this->assertSame(3000, $tritanium[0]['quantity']);
    }

    public function testParseItemListMergeIsCaseInsensitive(): void
    {
        $text = "Tritanium 1000\ntritanium 500";

        $result = $this->parser->parseItemList($text);

        $this->assertCount(1, $result);
        $this->assertSame(1500, $result[0]['quantity']);
    }

    public function testParseItemListSkipsEmptyLines(): void
    {
        $text = "Tritanium 1000\n\n\nPyerite 500\n\n";

        $result = $this->parser->parseItemList($text);

        $this->assertCount(2, $result);
    }

    public function testParseItemListHandlesWindowsLineEndings(): void
    {
        $text = "Tritanium 1000\r\nPyerite 500\r\nMexallon 200";

        $result = $this->parser->parseItemList($text);

        $this->assertCount(3, $result);
    }

    public function testParseItemListMixedFormats(): void
    {
        $text = implode("\n", [
            "100x Tritanium",
            "Pyerite\t500",
            "Mexallon x 200",
            "Isogen  300",
            "Nocxium 50",
        ]);

        $result = $this->parser->parseItemList($text);

        $this->assertCount(5, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(100, $result[0]['quantity']);
        $this->assertSame('Pyerite', $result[1]['name']);
        $this->assertSame(500, $result[1]['quantity']);
    }

    public function testParseItemListEmptyTextReturnsEmpty(): void
    {
        $result = $this->parser->parseItemList('');

        $this->assertSame([], $result);
    }

    public function testParseItemListWhitespaceOnlyReturnsEmpty(): void
    {
        $result = $this->parser->parseItemList("   \n  \n   ");

        $this->assertSame([], $result);
    }

    public function testParseItemListEveMultibuyFormat(): void
    {
        // Standard EVE multibuy format (from in-game copy)
        $text = "Tritanium\t10000\nPyerite\t5000\nMexallon\t2000";

        $result = $this->parser->parseItemList($text);

        $this->assertCount(3, $result);
        $this->assertSame('Tritanium', $result[0]['name']);
        $this->assertSame(10000, $result[0]['quantity']);
    }

    // ===========================================
    // Reflection helpers
    // ===========================================

    private function invokeParseLine(string $line): ?array
    {
        $method = new \ReflectionMethod(ItemParserService::class, 'parseLine');

        return $method->invoke($this->parser, $line);
    }
}
