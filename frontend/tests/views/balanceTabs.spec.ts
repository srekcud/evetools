import { defineComponent, h, reactive } from 'vue'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import Balance from '@/views/Balance.vue'

const route = reactive<{ path: string; params: Record<string, string>; query: LocationQuery }>({ path: '/balance', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const STORED_PERIOD_DAYS = 14

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

async function mountLedger(locale: 'en' | 'fr'): Promise<VueWrapper> {
  const wrapper = mount(Balance, {
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

function tabLabels(wrapper: VueWrapper): string[] {
  return wrapper.findAll('nav button').map(button => button.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  localStorage.setItem('ledgerSelectedDays', String(STORED_PERIOD_DAYS))
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(null as never)
  route.query = {}
})

describe('Balance tabs', () => {
  it('lists only the overview and settings tabs, in French and in English', async () => {
    const french = tabLabels(await mountLedger('fr'))
    const english = tabLabels(await mountLedger('en'))

    expect(french).toEqual([fr.ledger.tabs.dashboard, fr.ledger.tabs.settings])
    expect(english).toEqual([en.ledger.tabs.dashboard, en.ledger.tabs.settings])
  })

  it('has no industry tab any more, the real profit lives in Industry › Revenue', async () => {
    route.query = { tab: 'industry' }

    const wrapper = await mountLedger('en')

    expect(tabLabels(wrapper)).not.toContain('Industry')
    expect(wrapper.findComponent({ name: 'IndustryRevenueTab' }).exists()).toBe(false)
  })
})
