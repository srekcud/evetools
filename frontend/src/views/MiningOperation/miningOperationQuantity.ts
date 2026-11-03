export function formatCount(count: number, locale: string): string {
  return new Intl.NumberFormat(locale).format(count)
}

const UNIT_PRICE_MIN_FRACTION_DIGITS = 2
const UNIT_PRICE_MAX_FRACTION_DIGITS = 4
const PERCENT_FRACTION_DIGITS = 1

export function formatWhole(value: number, locale: string): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value)
}

export function formatPercent(value: number, locale: string): string {
  return new Intl.NumberFormat(locale, { minimumFractionDigits: PERCENT_FRACTION_DIGITS, maximumFractionDigits: PERCENT_FRACTION_DIGITS }).format(value)
}

export function formatUnitPrice(value: number, locale: string): string {
  return new Intl.NumberFormat(locale, { minimumFractionDigits: UNIT_PRICE_MIN_FRACTION_DIGITS, maximumFractionDigits: UNIT_PRICE_MAX_FRACTION_DIGITS }).format(value)
}
