import { ref } from 'vue'
import { usePartialPriceCoverages } from '@/composables/usePartialPriceCoverages'
import type { PartialPriceWarning } from '@/stores/industry/types'

const TRITANIUM_TYPE_ID = 34
const MEXALLON_TYPE_ID = 36
const DATACORE_MECHANICAL_ENGINEERING_TYPE_ID = 20424

function partialPrice(typeId: number, coveragePercent: number): PartialPriceWarning {
  return { reason: 'partial_market_depth', typeId, coveragePercent }
}

describe('usePartialPriceCoverages', () => {
  it('maps each partially priced type to its order book coverage as received', () => {
    const coverages = usePartialPriceCoverages(() => [
      partialPrice(TRITANIUM_TYPE_ID, 30),
      partialPrice(MEXALLON_TYPE_ID, 93.8),
      partialPrice(DATACORE_MECHANICAL_ENGINEERING_TYPE_ID, 99.7),
    ])

    expect([...coverages.value.entries()]).toEqual([
      [TRITANIUM_TYPE_ID, 30],
      [MEXALLON_TYPE_ID, 93.8],
      [DATACORE_MECHANICAL_ENGINEERING_TYPE_ID, 99.7],
    ])
  })

  it('has no coverage when no price is partial', () => {
    const coverages = usePartialPriceCoverages(() => [])

    expect(coverages.value.size).toBe(0)
  })

  it('keeps the lowest coverage of a type warned twice, at its first position', () => {
    const coverages = usePartialPriceCoverages(() => [
      partialPrice(TRITANIUM_TYPE_ID, 62),
      partialPrice(MEXALLON_TYPE_ID, 45),
      partialPrice(TRITANIUM_TYPE_ID, 30),
    ])

    expect([...coverages.value.entries()]).toEqual([
      [TRITANIUM_TYPE_ID, 30],
      [MEXALLON_TYPE_ID, 45],
    ])
  })

  it('follows the warnings when they change', () => {
    const warnings = ref<PartialPriceWarning[]>([])
    const coverages = usePartialPriceCoverages(() => warnings.value)

    warnings.value = [partialPrice(MEXALLON_TYPE_ID, 12.5)]

    expect([...coverages.value.entries()]).toEqual([[MEXALLON_TYPE_ID, 12.5]])
  })
})
