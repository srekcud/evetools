import { ref } from 'vue'
import { useMiningPricing } from '@/composables/useMiningPricing'
import type { MiningEntry } from '@/stores/ledger'
import {
  MYSTERY_ORE_TYPE_ID,
  SCORDITE_TYPE_ID,
  TRITANIUM_TYPE_ID,
  VELDSPAR_TYPE_ID,
  miningEntry,
} from '../components/ledger/miningLedgerHarness'

function pricing(entries: MiningEntry[] = []) {
  return useMiningPricing(ref(entries))
}

const STRUCTURE_COMPRESSED_VELDSPAR = miningEntry({ id: 'structure-compressed-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 3_000, structureCompressedUnitPrice: 1_100, structureCompressedEquivalentPrice: 1_100, reprocessValue: 13.32, unitValue: 900, usage: 'sold' })
const RAW_PRICED_TRITANIUM = miningEntry({ id: 'raw-tritanium', typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 2_500, rawUnitPrice: 4.27, unitValue: 4.27, usage: 'sold' })
const UNPRICED_MYSTERY_ORE = miningEntry({ id: 'mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', quantity: 3_150, usage: 'industry' })

describe('useMiningPricing unit value of an entry', () => {
  it('takes the unit value of the profile as it comes from the API, not the best of the prices of the entry', () => {
    const { getBestPriceValue } = pricing()

    expect(getBestPriceValue(STRUCTURE_COMPRESSED_VELDSPAR)).toBe(900)
  })

  it('takes the unit value of the profile even below the compressed equivalent price and the reprocess value of the entry', () => {
    const scordite = miningEntry({ id: 'scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 6_400, compressedEquivalentPrice: 8.1, reprocessValue: 9.5, reprocessYield: 0.78, unitValue: 7.2 })
    const { getBestPriceValue, getEntryValue } = pricing()

    expect(getBestPriceValue(scordite)).toBe(7.2)
    expect(getEntryValue(scordite)).toBeCloseTo(46_080, 6)
  })

  it('leaves unknown the unit value of an entry whose profile has no price, even when the entry carries other prices', () => {
    const unknownVeldspar = miningEntry({ id: 'unknown-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 12_000, unitPrice: 200, totalValue: 2_400_000, rawUnitPrice: 18.37, compressedEquivalentPrice: 21.4, reprocessValue: 15.5, unitValue: null })
    const { getBestPriceValue, getEntryValue } = pricing()

    expect(getBestPriceValue(unknownVeldspar)).toBeNull()
    expect(getEntryValue(unknownVeldspar)).toBeNull()
  })

  it('reads a unit value of zero as known', () => {
    const worthlessScordite = miningEntry({ id: 'worthless-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 6_400, rawUnitPrice: 31.9, unitValue: 0 })
    const { getBestPriceValue, getEntryValue } = pricing()

    expect(getBestPriceValue(worthlessScordite)).toBe(0)
    expect(getEntryValue(worthlessScordite)).toBe(0)
  })

  it('values an entry mined in a quantity of zero at zero even without a unit value', () => {
    const emptyMysteryOre = miningEntry({ id: 'empty-mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', quantity: 0 })
    const { getEntryValue } = pricing()

    expect(getEntryValue(emptyMysteryOre)).toBe(0)
  })
})

describe('useMiningPricing mining value by usage', () => {
  it('values an ore priced only by its raw unit price at its unit value times its quantity', () => {
    const { miningByUsage } = pricing([RAW_PRICED_TRITANIUM])

    expect(miningByUsage.value.sold).toBeCloseTo(10_675, 6)
    expect(miningByUsage.value.total).toBeCloseTo(10_675, 6)
  })

  it('makes unknown the usage holding an ore without any price, and the total, while the other usages stay known', () => {
    const soldVeldspar = miningEntry({ id: 'sold-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 7_000, reprocessValue: 15.5, reprocessYield: 0.78, unitValue: 15.5, usage: 'sold' })
    const industryTritanium = miningEntry({ id: 'industry-tritanium', typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 2_500, rawUnitPrice: 4.27, unitValue: 4.27, usage: 'industry' })
    const corpScordite = miningEntry({ id: 'corp-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 1_800, compressedEquivalentPrice: 24.25, unitValue: 24.25, usage: 'corp_project' })

    const { miningByUsage } = pricing([soldVeldspar, industryTritanium, UNPRICED_MYSTERY_ORE, corpScordite])

    expect(miningByUsage.value).toEqual({ sold: 108_500, corp_project: 43_650, industry: null, unknown: 0, total: null })
  })

  it('keeps known an ore without any price mined in a quantity of zero', () => {
    const corpScordite = miningEntry({ id: 'corp-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 1_800, compressedEquivalentPrice: 24.25, unitValue: 24.25, usage: 'corp_project' })
    const emptyMysteryOre = miningEntry({ id: 'empty-mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', quantity: 0, usage: 'corp_project' })

    const { miningByUsage } = pricing([corpScordite, emptyMysteryOre])

    expect(miningByUsage.value).toEqual({ sold: 0, corp_project: 43_650, industry: 0, unknown: 0, total: 43_650 })
  })
})
