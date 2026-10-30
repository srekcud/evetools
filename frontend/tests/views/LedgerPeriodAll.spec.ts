import { defineComponent, h, reactive } from 'vue'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { LedgerDailyStats, LedgerDashboard, LedgerSettings } from '@/stores/ledger'
import Ledger from '@/views/Ledger.vue'

const route = reactive<{ path: string; params: Record<string, string>; query: LocationQuery }>({ path: '/ledger', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const NOW = '2026-10-30T22:00:00Z'
const DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE = 274
const ACTIVE_PERIOD_CLASS = 'bg-cyan-600'

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

function dashboard(oldestEntryDate: string | null): LedgerDashboard {
  return {
    period: { from: '2026-09-30', to: '2026-10-30', days: 30 },
    totals: { total: 7_250_000, pve: 6_100_000, mining: 1_150_000, expenses: 320_000, profit: 6_930_000 },
    pveBreakdown: { bounties: 4_400_000, ess: 700_000, missions: 250_000, lootSales: 125_000, corpProjects: 625_000 },
    miningBreakdown: { sold: 1_150_000, corpProject: 0, industry: 0, unknown: 0 },
    expensesByType: { ammo: 320_000 },
    iskPerDay: 231_000,
    pvePercent: 84.14,
    miningPercent: 15.86,
    missingPriceTypeIds: [],
    lastSync: { pve: null, mining: null },
    settings: { corpProjectAccounting: 'pve' },
    oldestEntryDate,
  }
}

const DAILY_STATS: LedgerDailyStats = {
  period: { from: '2026-09-30', to: '2026-10-30', days: 30 },
  daily: [],
  missingPriceTypeIds: [],
}

const SETTINGS: LedgerSettings = {
  corpProjectAccounting: 'pve',
  autoSyncEnabled: true,
  lastMiningSyncAt: null,
  excludedTypeIds: [],
  defaultSoldTypeIds: [],
  updatedAt: '2026-10-01T08:00:00+00:00',
}

function respondWithDashboard(oldestEntryDate: string | null) {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith('/ledger/dashboard')) return dashboard(oldestEntryDate) as never
    if (endpoint.startsWith('/ledger/stats/daily')) return DAILY_STATS as never
    if (endpoint === '/ledger/settings') return SETTINGS as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountLedger(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const wrapper = mount(Ledger, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: MainLayoutStub,
        LedgerDashboardTab: true,
        PveTab: true,
        MiningTab: true,
        EscalationsTab: true,
        LedgerSettingsTab: true,
        LoadingSpinner: true,
        ErrorBanner: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

function buttonTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('button').map(button => button.text())
}

function buttonsLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').filter(button => button.text() === label)
}

function requestedDashboardEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint).filter(endpoint => endpoint.startsWith('/ledger/dashboard'))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(NOW))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('Ledger period selector All', () => {
  it('offers the All period right after YTD when the oldest entry date is known', async () => {
    respondWithDashboard('2026-01-31')

    const wrapper = await mountLedger()

    const texts = buttonTexts(wrapper)
    expect(texts.filter(text => text === 'All')).toHaveLength(1)
    expect(texts.indexOf('All')).toBe(texts.indexOf('YTD') + 1)
  })

  it('selects the days since the oldest entry plus one, reloads the dashboard on them and marks All as active', async () => {
    respondWithDashboard('2026-01-31')
    const wrapper = await mountLedger()

    const allButtons = buttonsLabelled(wrapper, 'All')
    expect(allButtons).toHaveLength(1)
    await allButtons[0].trigger('click')
    await flushPromises()

    expect(requestedDashboardEndpoints()).toEqual(['/ledger/dashboard?days=30', `/ledger/dashboard?days=${DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE}`])
    expect(localStorage.getItem('ledgerSelectedDays')).toBe(String(DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE))
    expect(buttonsLabelled(wrapper, 'All')[0].classes()).toContain(ACTIVE_PERIOD_CLASS)
    expect(buttonsLabelled(wrapper, 'YTD')[0].classes()).not.toContain(ACTIVE_PERIOD_CLASS)
    expect(buttonsLabelled(wrapper, '30 days')[0].classes()).not.toContain(ACTIVE_PERIOD_CLASS)
  })

  it('marks All as active when the stored period already covers the oldest entry', async () => {
    localStorage.setItem('ledgerSelectedDays', String(DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE))
    respondWithDashboard('2026-01-31')

    const wrapper = await mountLedger()

    expect(requestedDashboardEndpoints()).toEqual([`/ledger/dashboard?days=${DAYS_SINCE_THE_OLDEST_ENTRY_PLUS_ONE}`])
    expect(buttonsLabelled(wrapper, 'All').map(button => button.classes().includes(ACTIVE_PERIOD_CLASS))).toEqual([true])
  })

  it('names the All period Tout in French', async () => {
    respondWithDashboard('2026-01-31')

    const wrapper = await mountLedger('fr')

    expect(buttonsLabelled(wrapper, 'Tout')).toHaveLength(1)
  })

  it('offers no All period without any entry', async () => {
    respondWithDashboard(null)

    const wrapper = await mountLedger()

    expect(buttonTexts(wrapper)).toContain('YTD')
    expect(buttonTexts(wrapper)).not.toContain('All')
  })
})
