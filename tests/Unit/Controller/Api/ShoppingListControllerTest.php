<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;

/**
 * Tests for shopping list text parsing.
 */
class ShoppingListControllerTest extends TestCase
{
    // ===========================================
    // Line Parsing Tests
    // ===========================================

    public function testParseTabSeparated(): void
    {
        $result = $this->parseLine("Tritanium\t10000");
        $this->assertNotNull($result);
        $this->assertSame('Tritanium', $result['name']);
        $this->assertSame(10000, $result['quantity']);
    }

    public function testParseTabSeparatedWithCommas(): void
    {
        $result = $this->parseLine("Tritanium\t10,000,000");
        $this->assertNotNull($result);
        $this->assertSame('Tritanium', $result['name']);
        $this->assertSame(10000000, $result['quantity']);
    }

    public function testParseQuantityPrefix(): void
    {
        $result = $this->parseLine("200x Nocxium");
        $this->assertNotNull($result);
        $this->assertSame('Nocxium', $result['name']);
        $this->assertSame(200, $result['quantity']);
    }

    public function testParseQuantityPrefixWithSpace(): void
    {
        $result = $this->parseLine("200 x Carbon Fiber");
        $this->assertNotNull($result);
        $this->assertSame('Carbon Fiber', $result['name']);
        $this->assertSame(200, $result['quantity']);
    }

    public function testParseQuantitySuffix(): void
    {
        $result = $this->parseLine("Megacyte x 500");
        $this->assertNotNull($result);
        $this->assertSame('Megacyte', $result['name']);
        $this->assertSame(500, $result['quantity']);
    }

    public function testParseQuantitySuffixNoSpace(): void
    {
        $result = $this->parseLine("Pyerite x100");
        $this->assertNotNull($result);
        $this->assertSame('Pyerite', $result['name']);
        $this->assertSame(100, $result['quantity']);
    }

    public function testParseMultipleSpaces(): void
    {
        $result = $this->parseLine("Capital Construction Parts  50");
        $this->assertNotNull($result);
        $this->assertSame('Capital Construction Parts', $result['name']);
        $this->assertSame(50, $result['quantity']);
    }

    public function testParseSingleSpaceNumber(): void
    {
        $result = $this->parseLine("Isogen 5000");
        $this->assertNotNull($result);
        $this->assertSame('Isogen', $result['name']);
        $this->assertSame(5000, $result['quantity']);
    }

    public function testParseItemNameOnly(): void
    {
        $result = $this->parseLine("Tritanium");
        $this->assertNotNull($result);
        $this->assertSame('Tritanium', $result['name']);
        $this->assertSame(1, $result['quantity']);
    }

    public function testParseBulletPoint(): void
    {
        $result = $this->parseLine("- Tritanium 1000");
        $this->assertNotNull($result);
        $this->assertSame('Tritanium', $result['name']);
        $this->assertSame(1000, $result['quantity']);
    }

    public function testParseWithCommasInQuantity(): void
    {
        $result = $this->parseLine("1,000,000x Tritanium");
        $this->assertNotNull($result);
        $this->assertSame('Tritanium', $result['name']);
        $this->assertSame(1000000, $result['quantity']);
    }

    // ===========================================
    // Helper - Direct port of controller logic
    // ===========================================

    /**
     * Parse a single line into item name and quantity.
     * This is a direct port of the controller's parseLine method for testing.
     *
     * @return array{name: string, quantity: int}|null
     */
    private function parseLine(string $line): ?array
    {
        // Remove common prefixes/suffixes
        $line = preg_replace('/^\s*[-*•]\s*/', '', $line);

        // Format: "123x Item Name" or "123 x Item Name"
        if (preg_match('/^(\d[\d,\s]*)\s*x\s+(.+)$/i', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[1]);
            return ['name' => trim($matches[2]), 'quantity' => max(1, $quantity)];
        }

        // Format: "Item Name x 123" or "Item Name x123"
        if (preg_match('/^(.+?)\s+x\s*(\d[\d,\s]*)$/i', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);
            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        // Format: Tab-separated "Item Name\t123" (EVE clipboard)
        if (preg_match('/^(.+?)\t+(\d[\d,\s]*)$/', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);
            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        // Format: "Item Name  123" (multiple spaces before number at end)
        if (preg_match('/^(.+?)\s{2,}(\d[\d,\s]*)$/', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);
            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        // Format: "Item Name 123" (single space, number at very end)
        if (preg_match('/^(.+?)\s+(\d[\d,]*)$/', $line, $matches)) {
            $name = trim($matches[1]);
            // Avoid parsing things like "Capital Ship" where "Ship" is not a number
            if (!preg_match('/^\d/', $name)) {
                $quantity = (int) str_replace([',', ' '], '', $matches[2]);
                return ['name' => $name, 'quantity' => max(1, $quantity)];
            }
        }

        // Format: Just an item name (quantity = 1)
        if (preg_match('/^[a-zA-Z]/', $line)) {
            return ['name' => $line, 'quantity' => 1];
        }

        return null;
    }
}
