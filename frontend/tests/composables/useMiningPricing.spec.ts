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

const REPROCESS_YIELD = 78

function pricing(entries: MiningEntry[] = []) {
  return useMiningPricing(ref(entries), ref(REPROCESS_YIELD))
}

const STRUCTURE_COMPRESSED_VELDSPAR = miningEntry({ id: 'structure-compressed-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 3_000, structureCompressedUnitPrice: 1_100, structureCompressedEquivalentPrice: 1_100, reprocessValue: 13.32, usage: 'sold' })
const RAW_PRICED_TRITANIUM = miningEntry({ id: 'raw-tritanium', typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 2_500, rawUnitPrice: 4.27, usage: 'sold' })
const UNPRICED_MYSTERY_ORE = miningEntry({ id: 'mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', quantity: 3_150, usage: 'industry' })

describe('useMiningPricing best unit price of an entry', () => {
  it('takes the compressed equivalent price of the structure as it comes from the API', () => {
    const { getBestPriceValue, getBestPrice } = pricing()

    expect(getBestPriceValue(STRUCTURE_COMPRESSED_VELDSPAR)).toBe(1_100)
    expect(getBestPrice(STRUCTURE_COMPRESSED_VELDSPAR)).toEqual({ value: 1_100, color: 'text-teal-400', source: 'Structure compresse' })
  })

  it('ignores the compressed unit price of the structure when its compressed equivalent price is unknown', () => {
    const scorditeWithoutStructureEquivalent = miningEntry({ id: 'scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 6_400, structureCompressedUnitPrice: 2_350, structureCompressedEquivalentPrice: null, reprocessValue: 9.5 })
    const { getBestPriceValue, getBestPrice } = pricing()

    expect(getBestPriceValue(scorditeWithoutStructureEquivalent)).toBe(9.5)
    expect(getBestPrice(scorditeWithoutStructureEquivalent)).toEqual({ value: 9.5, color: 'text-purple-400', source: 'Jita reprocess 78%' })
  })

  it('falls back to the raw unit price when no other source exists', () => {
    const { getBestPriceValue, getBestPrice } = pricing()

    expect(getBestPriceValue(RAW_PRICED_TRITANIUM)).toBe(4.27)
    expect(getBestPrice(RAW_PRICED_TRITANIUM)).toEqual({ value: 4.27, color: 'text-slate-400', source: '' })
  })

  it('prefers a positive raw unit price over a compressed equivalent price brought to zero by the export tax', () => {
    const taxedVeldspar = miningEntry({ id: 'taxed-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 5_200, compressedEquivalentPrice: 0, rawUnitPrice: 17.85 })
    const { getBestPriceValue } = pricing()

    expect(getBestPriceValue(taxedVeldspar)).toBe(17.85)
  })

  it('keeps the stored value per unit before the raw unit price', () => {
    const storedVeldspar = miningEntry({ id: 'stored-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 12_000, unitPrice: 200, totalValue: 2_400_000, rawUnitPrice: 18.37 })
    const { getBestPriceValue } = pricing()

    expect(getBestPriceValue(storedVeldspar)).toBe(200)
  })

  it('labels the stored value per unit as a fallback without source', () => {
    const storedVeldspar = miningEntry({ id: 'stored-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 12_000, unitPrice: 200, totalValue: 2_400_000, rawUnitPrice: 18.37 })
    const { getBestPrice } = pricing()

    expect(getBestPrice(storedVeldspar)).toEqual({ value: 200, color: 'text-slate-400', source: '' })
  })

  it('reads a stored total value of zero as zero per unit, not as a missing value', () => {
    const worthlessScordite = miningEntry({ id: 'worthless-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 6_400, totalValue: 0, rawUnitPrice: 31.9 })
    const { getBestPriceValue } = pricing()

    expect(getBestPriceValue(worthlessScordite)).toBe(0)
  })

  it('values at zero, known, an ore whose only price is a compressed equivalent brought to zero', () => {
    const zeroPricedVeldspar = miningEntry({ id: 'zero-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 4_100, compressedEquivalentPrice: 0 })
    const { getBestPriceValue } = pricing()

    expect(getBestPriceValue(zeroPricedVeldspar)).toBe(0)
  })

  it('has an unknown best unit price for an ore without any price', () => {
    const { getBestPriceValue, getBestPrice } = pricing()

    expect(getBestPriceValue(UNPRICED_MYSTERY_ORE)).toBeNull()
    expect(getBestPrice(UNPRICED_MYSTERY_ORE).value).toBeNull()
    expect(getBestPrice(UNPRICED_MYSTERY_ORE).source).toBe('')
  })
})

describe('useMiningPricing mining value by usage', () => {
  it('values an ore priced only by its raw unit price at best unit price times quantity', () => {
    const { miningByUsage } = pricing([RAW_PRICED_TRITANIUM])

    expect(miningByUsage.value.sold).toBeCloseTo(10_675, 6)
    expect(miningByUsage.value.total).toBeCloseTo(10_675, 6)
  })

  it('makes unknown the usage holding an ore without any price, and the total, while the other usages stay known', () => {
    const soldVeldspar = miningEntry({ id: 'sold-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 7_000, reprocessValue: 15.5, usage: 'sold' })
    const industryTritanium = miningEntry({ id: 'industry-tritanium', typeId: TRITANIUM_TYPE_ID, typeName: 'Tritanium', quantity: 2_500, rawUnitPrice: 4.27, usage: 'industry' })
    const corpScordite = miningEntry({ id: 'corp-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 1_800, compressedEquivalentPrice: 24.25, usage: 'corp_project' })

    const { miningByUsage } = pricing([soldVeldspar, industryTritanium, UNPRICED_MYSTERY_ORE, corpScordite])

    expect(miningByUsage.value).toEqual({ sold: 108_500, corp_project: 43_650, industry: null, unknown: 0, total: null })
  })

  it('keeps known an ore without any price mined in a quantity of zero', () => {
    const corpScordite = miningEntry({ id: 'corp-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 1_800, compressedEquivalentPrice: 24.25, usage: 'corp_project' })
    const emptyMysteryOre = miningEntry({ id: 'empty-mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', quantity: 0, usage: 'corp_project' })

    const { miningByUsage } = pricing([corpScordite, emptyMysteryOre])

    expect(miningByUsage.value).toEqual({ sold: 0, corp_project: 43_650, industry: 0, unknown: 0, total: 43_650 })
  })
})
