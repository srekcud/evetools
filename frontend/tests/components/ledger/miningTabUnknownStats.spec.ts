import { enableAutoUnmount } from '@vue/test-utils'
import en from '@/i18n/locales/en.json'
import type { MiningStats } from '@/stores/ledger'
import { apiRequest } from '@/services/api'
import {
  JITA_SYSTEM_ID,
  PERIMETER_SYSTEM_ID,
  SCORDITE_TYPE_ID,
  VELDSPAR_TYPE_ID,
  card,
  cardValue,
  miningEntry,
  mountTab,
  respondWith,
  type MiningTabWrapper,
} from './miningLedgerHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SOLD_VELDSPAR_ENTRY = miningEntry({ id: 'veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', solarSystemId: JITA_SYSTEM_ID, solarSystemName: 'Jita', date: '2026-10-20T00:00:00+00:00', quantity: 12_000, unitPrice: 200, totalValue: 2_400_000, usage: 'sold' })
const CORP_SCORDITE_ENTRY = miningEntry({ id: 'scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', solarSystemId: PERIMETER_SYSTEM_ID, solarSystemName: 'Perimeter', date: '2026-10-21T00:00:00+00:00', quantity: 4_500, unitPrice: 200, totalValue: 900_000, usage: 'corp_project' })

const KNOWN_MINING_STATS: MiningStats = {
  period: { from: '2026-09-22', to: '2026-10-22', days: 30 },
  totals: { totalValue: 3_300_000, totalQuantity: 16_500 },
  byUsage: {},
  iskPerDay: 110_000,
  missingPriceTypeIds: [],
}

function oreBreakdownTotal(wrapper: MiningTabWrapper) {
  const breakdown = card(wrapper, en.ledger.mining.oreBreakdown)
  return breakdown.findAll('span').find(span => span.text().endsWith('total'))!
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('MiningTab stats cards while the mining stats are unknown', () => {
  beforeEach(() => {
    respondWith(
      () => Promise.reject(new Error('stats unavailable')),
      () => Promise.resolve([SOLD_VELDSPAR_ENTRY, CORP_SCORDITE_ENTRY]),
    )
  })

  it('shows the total value as unknown instead of 0', async () => {
    const wrapper = await mountTab()

    const totalValue = cardValue(wrapper, en.ledger.mining.totalValue)
    expect(totalValue.textContent?.trim()).toBe('—')
    expect(card(wrapper, en.ledger.mining.totalValue).get('[title]').attributes('title')).toBe('Unknown value')
  })

  it('shows the ISK per active day as unknown instead of 0', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.iskPerActiveDay).textContent?.trim()).toBe('—')
    expect(card(wrapper, en.ledger.mining.iskPerActiveDay).text()).toContain('2 active days')
  })

  it('shows the mined volume as unknown instead of 0', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.minedVolume).textContent?.trim()).toBe('—')
  })

  it('shows the main system share of volume as unknown instead of 0%', async () => {
    const wrapper = await mountTab()

    const mainSystem = card(wrapper, en.ledger.mining.mainSystem)
    expect(mainSystem.text()).toContain('Jita')
    expect(mainSystem.text()).not.toContain('0% of volume')
    expect(mainSystem.text()).toContain('—')
  })

  it('shows the ore breakdown total as unknown instead of 0', async () => {
    const wrapper = await mountTab()

    const total = oreBreakdownTotal(wrapper)
    expect(total.text()).toContain('—')
    expect(total.text()).not.toMatch(/\d/)
  })

  it('still shows the known personal and corp values computed from the entries', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.personal).textContent?.trim()).toBe('2.40 M')
    expect(cardValue(wrapper, en.ledger.mining.corp).textContent?.trim()).toBe('900.00 K')
  })
})

describe('MiningTab stats cards while the ledger is loading', () => {
  it('shows busy skeletons without digits for the total value and the ISK per active day', async () => {
    respondWith(() => new Promise(() => {}), () => new Promise(() => {}))

    const wrapper = await mountTab()

    for (const label of [en.ledger.mining.totalValue, en.ledger.mining.iskPerActiveDay]) {
      const value = card(wrapper, label)
      expect(value.find('[aria-busy="true"]').exists()).toBe(true)
      expect(value.element.children[1].textContent).not.toMatch(/\d/)
    }
  })
})

describe('MiningTab stats cards once the mining stats are known', () => {
  beforeEach(() => {
    respondWith(
      () => Promise.resolve(KNOWN_MINING_STATS),
      () => Promise.resolve([SOLD_VELDSPAR_ENTRY, CORP_SCORDITE_ENTRY]),
    )
  })

  it('shows the known totals, ISK per active day and main system share', async () => {
    const wrapper = await mountTab()

    expect(cardValue(wrapper, en.ledger.mining.totalValue).textContent?.trim()).toBe('3.30 M')
    expect(cardValue(wrapper, en.ledger.mining.iskPerActiveDay).textContent?.trim()).toBe('1.65 M')
    expect(cardValue(wrapper, en.ledger.mining.minedVolume).textContent?.trim()).toBe('16,500')
    expect(card(wrapper, en.ledger.mining.mainSystem).text()).toContain('73% of volume')
    expect(oreBreakdownTotal(wrapper).text()).toBe('3.30 M total')
  })
})
