import { enableAutoUnmount } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import type { MiningStats } from '@/stores/ledger'
import { apiRequest } from '@/services/api'
import {
  JITA_SYSTEM_ID,
  MYSTERY_ORE_TYPE_ID,
  PERIMETER_SYSTEM_ID,
  SCORDITE_TYPE_ID,
  UNLOADED_ORE_TYPE_ID,
  VELDSPAR_TYPE_ID,
  card,
  cardShare,
  cardValue,
  miningEntry,
  mountTab,
  respondWith,
} from './miningLedgerHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SOLD_MYSTERY_ORE_ENTRY = miningEntry({ id: 'mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', solarSystemId: JITA_SYSTEM_ID, solarSystemName: 'Jita', date: '2026-10-20T00:00:00+00:00', quantity: 3_150, usage: 'sold' })
const SOLD_VELDSPAR_ENTRY = miningEntry({ id: 'veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', solarSystemId: PERIMETER_SYSTEM_ID, solarSystemName: 'Perimeter', date: '2026-10-21T00:00:00+00:00', quantity: 12_000, reprocessValue: 8.75, usage: 'sold' })
const CORP_SCORDITE_ENTRY = miningEntry({ id: 'scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', solarSystemId: JITA_SYSTEM_ID, solarSystemName: 'Jita', date: '2026-10-21T00:00:00+00:00', quantity: 4_500, rawUnitPrice: 22.6, usage: 'corp_project' })

const MINING_STATS_WITH_ORES_WITHOUT_PRICE: MiningStats = {
  period: { from: '2026-09-22', to: '2026-10-22', days: 30 },
  totals: { totalValue: null, totalQuantity: 19_650 },
  byUsage: {},
  iskPerDay: null,
  missingPriceTypeIds: [MYSTERY_ORE_TYPE_ID, UNLOADED_ORE_TYPE_ID],
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  respondWith(
    () => Promise.resolve(MINING_STATS_WITH_ORES_WITHOUT_PRICE),
    () => Promise.resolve([SOLD_MYSTERY_ORE_ENTRY, SOLD_VELDSPAR_ENTRY, CORP_SCORDITE_ENTRY]),
  )
})

describe('MiningTab while some mined ores have no price', () => {
  it('lists the ores without a price under an unknown total value, by name when loaded, else by typeId', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.totalValue).textContent?.trim()).toBe('—')
    expect(card(wrapper, en.ledger.mining.totalValue).get('[title]').attributes('title')).toBe('Unknown value')
    expect(wrapper.findAll('*').some(element => element.text() === 'Ores without a price: Mystery Ore, #81144')).toBe(true)
  })

  it('shows the ISK per active day as unknown instead of dividing an unknown total', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.iskPerActiveDay).textContent?.trim()).toBe('—')
    expect(card(wrapper, en.ledger.mining.iskPerActiveDay).text()).toContain('2 active days')
  })

  it('shows the main system share of value as unknown instead of 0%', async () => {
    const wrapper = await mountTab()

    const mainSystemShare = cardShare(wrapper, en.ledger.mining.mainSystem)
    expect(mainSystemShare.textContent?.trim()).toBe('—')
  })

  it('shows the personal value and share as unknown when a sold ore has no price', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.personal).textContent?.trim()).toBe('—')
    expect(cardShare(wrapper, en.ledger.mining.personal).textContent?.trim()).toBe('—')
  })

  it('keeps the known corp value but shows its share of an unknown total as unknown', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.corp).textContent?.trim()).toBe('101.70 K')
    expect(cardShare(wrapper, en.ledger.mining.corp).textContent?.trim()).toBe('—')
  })

  it('keeps the uncategorized value known at zero', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.usage.unknown).textContent?.trim()).toBe('0')
  })

  it('draws no personal segment in the ore breakdown bar', async () => {
    const wrapper = await mountTab()

    const breakdown = card(wrapper, en.ledger.mining.oreBreakdown)
    expect(breakdown.find('.bg-emerald-500.h-full').exists()).toBe(false)
  })
})
