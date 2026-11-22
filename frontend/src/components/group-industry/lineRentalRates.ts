const KEY_SEPARATOR = /_(\w)/g

export type LineRentalRateUnit = 'perRun' | 'perDay'

export type LineRentalRate = {
  key: string
  value: number
  unit: LineRentalRateUnit
}

export function defaultLineRentalRates(): LineRentalRate[] {
  return [
    { key: 'component_t1', value: 250, unit: 'perRun' },
    { key: 'module_t1', value: 10_000, unit: 'perRun' },
    { key: 'ship_t1', value: 100_000, unit: 'perRun' },
    { key: 'module_t2', value: 100_000, unit: 'perRun' },
    { key: 'ship_t2', value: 2_500_000, unit: 'perRun' },
    { key: 'capital_t1', value: 25_000, unit: 'perRun' },
    { key: 'capital_t2', value: 125_000, unit: 'perRun' },
    { key: 'location_factor', value: 1_000_000, unit: 'perDay' },
  ]
}

export function lineRentalRateLabelKey(rateKey: string): string {
  return `groupIndustry.modals.lineRental.${rateKey.replace(KEY_SEPARATOR, (_, letter: string) => letter.toUpperCase())}`
}

export function lineRentalRateUnitKey(unit: LineRentalRateUnit): string {
  return `groupIndustry.modals.lineRental.${unit}`
}
