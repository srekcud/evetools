/**
 * Parse ISK values with shortcuts:
 * - k or K = thousands (1k = 1,000)
 * - m or M = millions (1M = 1,000,000)
 * - b or B = billions (1B = 1,000,000,000)
 *
 * Supports formats like: 10k, 1.5M, 2.5B, 500, 1,234,567
 */
export function parseIskValue(input: string): number | null {
  if (!input || input.trim() === '') {
    return null
  }

  // Remove spaces and commas
  let value = input.trim().replace(/[\s,]/g, '')

  // Check for suffix multipliers
  const lastChar = value.slice(-1).toLowerCase()
  let multiplier = 1

  if (lastChar === 'k') {
    multiplier = 1_000
    value = value.slice(0, -1)
  } else if (lastChar === 'm') {
    multiplier = 1_000_000
    value = value.slice(0, -1)
  } else if (lastChar === 'b') {
    multiplier = 1_000_000_000
    value = value.slice(0, -1)
  }

  // Parse the numeric part
  const numValue = parseFloat(value)

  if (isNaN(numValue)) {
    return null
  }

  return Math.round(numValue * multiplier * 100) / 100 // Round to 2 decimal places
}

/**
 * Format a number with ISK suffix for display in input placeholder
 */
export function formatIskShort(value: number | null): string {
  if (value === null || value === undefined) {
    return ''
  }

  if (value >= 1_000_000_000) {
    return `${(value / 1_000_000_000).toFixed(2)}B`
  }
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(2)}M`
  }
  if (value >= 1_000) {
    return `${(value / 1_000).toFixed(1)}k`
  }
  return value.toString()
}

export function useIskParser() {
  return {
    parseIskValue,
    formatIskShort,
  }
}
