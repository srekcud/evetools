import { parseEveStock } from '@/composables/useStockAnalysis'

describe('parseEveStock', () => {
  // ---------------------------------------------------------------------------
  // Empty / blank input
  // ---------------------------------------------------------------------------
  it('returns empty array for empty string', () => {
    expect(parseEveStock('')).toEqual([])
  })

  it('returns empty array for whitespace-only string', () => {
    expect(parseEveStock('   \n  \t  ')).toEqual([])
  })

  // ---------------------------------------------------------------------------
  // Tab-separated format (EVE inventory copy)
  // ---------------------------------------------------------------------------
  describe('tab-separated format', () => {
    it('parses simple tab-separated lines', () => {
      const input = 'Tritanium\t5000\nPyerite\t2000'
      const result = parseEveStock(input)
      expect(result).toEqual([
        { name: 'Tritanium', quantity: 5000 },
        { name: 'Pyerite', quantity: 2000 },
      ])
    })

    it('parses items with multiple tab-separated columns', () => {
      // EVE copy often includes extra columns (group, category, etc.)
      const input = 'Tritanium\t5000\tMineral\tMaterial'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 5000 }])
    })

    it('parses quantities with comma separators', () => {
      const input = 'Tritanium\t1,500,000'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 1500000 }])
    })

    it('parses quantities with space separators', () => {
      const input = 'Tritanium\t1 500 000'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 1500000 }])
    })
  })

  // ---------------------------------------------------------------------------
  // Space-separated format with quantity at end
  // ---------------------------------------------------------------------------
  describe('space-separated format', () => {
    it('parses name followed by quantity', () => {
      const input = 'Tritanium 5000'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 5000 }])
    })

    it('parses multi-word item names', () => {
      const input = 'Condensed Alloy 1200'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Condensed Alloy', quantity: 1200 }])
    })

    it('handles m3 unit suffix', () => {
      const input = 'Tritanium 5000 m3'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 5000 }])
    })

    it('handles units suffix', () => {
      const input = 'Tritanium 5000 units'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 5000 }])
    })
  })

  // ---------------------------------------------------------------------------
  // Fallback: item name only (quantity defaults to 1)
  // ---------------------------------------------------------------------------
  describe('fallback single item name', () => {
    it('assigns quantity 1 to lines with no number', () => {
      const input = 'Tritanium'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 1 }])
    })

    it('assigns quantity 1 to multi-word names with no number', () => {
      const input = 'Large Shield Extender II'
      const result = parseEveStock(input)
      // Should fallback to qty 1 (the "II" contains letters so last word is not purely numeric)
      expect(result).toHaveLength(1)
      expect(result[0].quantity).toBe(1)
    })
  })

  // ---------------------------------------------------------------------------
  // Skipped lines
  // ---------------------------------------------------------------------------
  describe('line filtering', () => {
    it('skips empty lines', () => {
      const input = 'Tritanium\t5000\n\n\nPyerite\t2000'
      const result = parseEveStock(input)
      expect(result).toHaveLength(2)
    })

    it('skips Total: lines', () => {
      const input = 'Tritanium\t5000\nTotal: 5000'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 5000 }])
    })

    it('skips total: lines (case insensitive)', () => {
      const input = 'total: 100\nTritanium\t200'
      const result = parseEveStock(input)
      expect(result).toEqual([{ name: 'Tritanium', quantity: 200 }])
    })
  })

  // ---------------------------------------------------------------------------
  // Duplicate handling
  // ---------------------------------------------------------------------------
  describe('duplicate items', () => {
    it('creates separate entries for duplicate item names', () => {
      // parseEveStock itself does not merge duplicates - that is done by the
      // composable when building the stockMap. The parser returns raw entries.
      const input = 'Tritanium\t5000\nTritanium\t3000'
      const result = parseEveStock(input)
      expect(result).toHaveLength(2)
      expect(result[0]).toEqual({ name: 'Tritanium', quantity: 5000 })
      expect(result[1]).toEqual({ name: 'Tritanium', quantity: 3000 })
    })
  })

  // ---------------------------------------------------------------------------
  // Windows line endings
  // ---------------------------------------------------------------------------
  it('handles Windows-style CRLF line endings', () => {
    const input = 'Tritanium\t5000\r\nPyerite\t2000\r\n'
    const result = parseEveStock(input)
    expect(result).toEqual([
      { name: 'Tritanium', quantity: 5000 },
      { name: 'Pyerite', quantity: 2000 },
    ])
  })

  // ---------------------------------------------------------------------------
  // Mixed formats
  // ---------------------------------------------------------------------------
  it('parses a mix of tab-separated and space-separated lines', () => {
    const input = 'Tritanium\t5000\nPyerite 2000\nMexallon'
    const result = parseEveStock(input)
    expect(result).toHaveLength(3)
    expect(result[0]).toEqual({ name: 'Tritanium', quantity: 5000 })
    expect(result[1]).toEqual({ name: 'Pyerite', quantity: 2000 })
    expect(result[2]).toEqual({ name: 'Mexallon', quantity: 1 })
  })

  // ---------------------------------------------------------------------------
  // Real-world EVE cargo scan format
  // ---------------------------------------------------------------------------
  it('parses realistic EVE cargo scan output', () => {
    const input = [
      'Tritanium\t23 574\tMineral\t23 574,00 m3',
      'Pyerite\t8 042\tMineral\t8 042,00 m3',
      'Mexallon\t1 203\tMineral\t1 203,00 m3',
      'Isogen\t501\tMineral\t501,00 m3',
    ].join('\n')
    const result = parseEveStock(input)
    expect(result).toHaveLength(4)
    expect(result[0]).toEqual({ name: 'Tritanium', quantity: 23574 })
    expect(result[1]).toEqual({ name: 'Pyerite', quantity: 8042 })
    expect(result[2]).toEqual({ name: 'Mexallon', quantity: 1203 })
    expect(result[3]).toEqual({ name: 'Isogen', quantity: 501 })
  })

  // ---------------------------------------------------------------------------
  // Edge cases
  // ---------------------------------------------------------------------------
  it('ignores lines with zero quantity', () => {
    const input = 'Tritanium\t0'
    const result = parseEveStock(input)
    expect(result).toEqual([])
  })

  it('handles quantity with period as thousands separator', () => {
    // parseQuantity removes periods before groups of 3 digits
    const input = 'Tritanium\t1.500.000'
    const result = parseEveStock(input)
    expect(result).toEqual([{ name: 'Tritanium', quantity: 1500000 }])
  })
})
