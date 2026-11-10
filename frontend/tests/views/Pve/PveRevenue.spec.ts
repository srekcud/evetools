import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { apiRequest } from '@/services/api'
import type { LedgerDashboard } from '@/stores/ledger'
import PveRevenue from '@/views/Pve/PveRevenue.vue'
import { buttonsLabelled, currentTabTargets, mountPveModulePage, periodOfPveTab } from './pveModuleHarness'

const route = reactive<{ path: string; params: Record<string, string>; query: Record<string, string> }>({ path: '/pve/revenue', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NOW = '2026-10-30T22:00:00Z'
const STORED_PERIOD_DAYS = 60
const CHOSEN_PERIOD_DAYS = 90
const DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE = 274

const DASHBOARD_OLDEST_ENTRY_JANUARY: LedgerDashboard = {
  period: { from: '2026-09-30', to: '2026-10-30', days: 30 },
  totals: { total: 7_250_000, pve: 6_100_000, mining: 1_150_000, industry: 0, expenses: 320_000, profit: 6_930_000 },
  industryBreakdown: { profit: 0, projectCount: 0, unknownProjectCount: 0 },
  pveBreakdown: { bounties: 4_400_000, ess: 700_000, missions: 250_000, lootSales: 125_000, corpProjects: 625_000, escalations: 0 },
  miningBreakdown: { sold: 1_150_000, corpProject: 0, industry: 0, unknown: 0 },
  expensesByType: { ammo: 320_000 },
  iskPerDay: 231_000,
  pvePercent: 84.14,
  miningPercent: 15.86,
  industryPercent: 0,
  missingPriceTypeIds: [],
  lastSync: { pve: null, mining: null },
  settings: { corpProjectAccounting: 'pve' },
  oldestEntryDate: '2026-01-31',
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith('/ledger/dashboard')) return DASHBOARD_OLDEST_ENTRY_JANUARY as never
    return new Promise(() => {}) as never
  })
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('PveRevenue', () => {
  it('opens the revenue tab, starts the pve tab on the stored period and hands it the period chosen next', async () => {
    localStorage.setItem('ledgerSelectedDays', String(STORED_PERIOD_DAYS))
    const wrapper = await mountPveModulePage(PveRevenue)
    const periodAtStart = periodOfPveTab(wrapper)

    await buttonsLabelled(wrapper, `${CHOSEN_PERIOD_DAYS} days`)[0]?.trigger('click')
    await flushPromises()

    expect({
      currentTab: currentTabTargets(wrapper),
      periodAtStart,
      periodAfterChoice: periodOfPveTab(wrapper),
      storedPeriod: localStorage.getItem('ledgerSelectedDays'),
    }).toEqual({
      currentTab: [{ name: 'pve-revenue' }],
      periodAtStart: STORED_PERIOD_DAYS,
      periodAfterChoice: CHOSEN_PERIOD_DAYS,
      storedPeriod: String(CHOSEN_PERIOD_DAYS),
    })
  })

  it('loads the balance dashboard so the All period covers the days since the oldest entry plus one', async () => {
    const wrapper = await mountPveModulePage(PveRevenue)

    const allButtons = buttonsLabelled(wrapper, 'All')
    await allButtons[0]?.trigger('click')
    await flushPromises()

    expect({
      allButtons: allButtons.length,
      firstDashboardRequest: vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).find(endpoint => endpoint.startsWith('/ledger/dashboard')),
      period: periodOfPveTab(wrapper),
    }).toEqual({
      allButtons: 1,
      firstDashboardRequest: '/ledger/dashboard?days=30',
      period: DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE,
    })
  })
})
