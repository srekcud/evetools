import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BalanceRevenueGrid from '@/components/balance/BalanceRevenueGrid.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PERIOD = { from: '2026-10-18', to: '2026-11-17', days: 30 }
const NO_COLUMN = { value: 0, previous: 0, change: null, daily: {}, contributors: null }

const RESPONSES: Record<string, unknown> = {
  '/pve/stats/grid': {
    period: PERIOD,
    columns: { bounties: { ...NO_COLUMN, value: 500_000_000 }, ess: NO_COLUMN, missions: NO_COLUMN, lootSales: NO_COLUMN, escalations: NO_COLUMN, expenses: NO_COLUMN },
    net: { value: 500_000_000, previous: 0, change: null, daily: {} },
  },
  '/ledger/mining/stats/grid': {
    period: PERIOD,
    columns: {
      sold: { value: 200_000_000, previous: null, change: null, quantity: 10, daily: {}, contributors: [] },
      corp_project: { value: 0, previous: null, change: null, quantity: 0, daily: {}, contributors: [] },
      unknown: { value: 30_000_000, previous: null, change: null, quantity: 4, daily: null, contributors: [] },
    },
    net: { value: 200_000_000, previous: null, change: null, daily: {}, contributors: [] },
    missingPriceTypeIds: [],
    corpProjectCountedInPve: false,
  },
  '/ledger/industry': {
    period: PERIOD,
    totals: { profit: 100_000_000, projectCount: 3, unknownProjectCount: 1, capital: 400_000_000, slotHours: null, iskPerHour: null, change: null },
    daily: {},
    months: [],
    products: [],
  },
  '/planetary/revenue': {
    periodEnd: '2026-11-17',
    days: 30,
    tiers: [],
    realisedIsk: 0,
    realisedChange: null,
    realisedDaily: [],
    realisedContributors: [],
    estimate: { dailyIsk: 1_000_000, periodIsk: 30_000_000, contributors: [] },
    toFix: [],
  },
}

function answerWith(responses: Record<string, unknown> = RESPONSES) {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => responses[endpoint.split('?')[0]] as never)
}

async function mountGrid(days = 30, locale: 'en' | 'fr' = 'en') {
  const wrapper = mount(BalanceRevenueGrid, {
    props: { days },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { RouterLink: RouterLinkStub } },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  answerWith()
})

describe('BalanceRevenueGrid', () => {
  it('reads the four modules over the chosen period, the planetary one capped at its maximum', async () => {
    await mountGrid(500)

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).sort()).toEqual([
      '/ledger/industry?days=500',
      '/ledger/mining/stats/grid?days=500',
      '/planetary/revenue?days=365',
      '/pve/stats/grid?days=500',
    ])
  })

  it('shows one aggregated row with the four modules and the not valued column, the net adding the estimate marked with a tilde', async () => {
    const wrapper = await mountGrid()

    expect(wrapper.get('[data-cell="pve"]').text()).toBe('500.00 M')
    expect(wrapper.get('[data-cell="mining"]').text()).toBe('200.00 M')
    expect(wrapper.get('[data-cell="industry"]').text()).toBe('2100.00 M')
    expect(wrapper.get('[data-cell="industry"] [data-testid="cell-badge"]').text()).toBe('2')
    expect(wrapper.get('[data-cell="planetary"]').text()).toBe('~30.00 M')
    expect(wrapper.get('[data-cell="unknown"] div').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('~830.00 M')
  })

  it('links each column to the revenue page of its module', async () => {
    const wrapper = await mountGrid()

    expect(wrapper.get('[data-testid="revenue-line-grid"]').findAllComponents(RouterLinkStub).map((link: { props: () => Record<string, unknown> }) => link.props().to)).toEqual([
      { name: 'pve-revenue' },
      { name: 'mining-my-mining' },
      { name: 'industry-revenue' },
      { name: 'planetary', query: { tab: 'revenue' } },
      { name: 'mining-my-mining' },
    ])
  })

  it('aggregates the issues of the modules in the To fix panel, each with its action', async () => {
    const wrapper = await mountGrid()

    const panel = wrapper.get('[data-testid="revenue-todo-panel"]')
    expect(panel.text()).toContain('4 units of uncategorised ore')
    expect(panel.text()).toContain('1 industry project with an unknown profit')
    expect(panel.findAllComponents(RouterLinkStub).map((link: { props: () => Record<string, unknown> }) => link.props().to)).toEqual([{ name: 'mining-my-mining' }, { name: 'industry-projects' }])
  })

  it('words the grid in French', async () => {
    const wrapper = await mountGrid(30, 'fr')

    expect(wrapper.text()).toContain('Revenus · toutes activités')
    expect(wrapper.text()).toContain('Non valorisé')
    expect(wrapper.text()).toContain('Planétaire (estimation)')
    expect(wrapper.text()).not.toContain('revenue.balance')
  })

  it('reads the modules again when the period changes', async () => {
    const wrapper = await mountGrid(30)

    await wrapper.setProps({ days: 7 })
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toContain('/pve/stats/grid?days=7')
  })
})
