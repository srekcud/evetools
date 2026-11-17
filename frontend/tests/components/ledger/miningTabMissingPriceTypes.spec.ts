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
const SOLD_VELDSPAR_ENTRY = miningEntry({ id: 'veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', solarSystemId: PERIMETER_SYSTEM_ID, solarSystemName: 'Perimeter', date: '2026-10-21T00:00:00+00:00', quantity: 12_000, reprocessValue: 8.75, unitValue: 8.75, usage: 'sold' })
const CORP_SCORDITE_ENTRY = miningEntry({ id: 'scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', solarSystemId: JITA_SYSTEM_ID, solarSystemName: 'Jita', date: '2026-10-21T00:00:00+00:00', quantity: 4_500, rawUnitPrice: 22.6, unitValue: 22.6, usage: 'corp_project' })

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
})
