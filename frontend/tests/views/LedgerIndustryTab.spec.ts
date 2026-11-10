import { defineComponent, h, reactive } from 'vue'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LedgerIndustryTab from '@/components/ledger/LedgerIndustryTab.vue'
import { apiRequest } from '@/services/api'
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

const STORED_PERIOD_DAYS = 14

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

async function mountLedger(locale: 'en' | 'fr'): Promise<VueWrapper> {
  const wrapper = mount(Ledger, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: MainLayoutStub,
        LedgerDashboardTab: true,
        LedgerIndustryTab: true,
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

describe('Ledger industry tab', () => {
  it('lists the Industrie tab between PVE and Escalations, in French and in English', async () => {
    const french = tabLabels(await mountLedger('fr'))
    const english = tabLabels(await mountLedger('en'))

    expect(french.indexOf('Industrie')).toBe(french.indexOf(fr.ledger.tabs.pve) + 1)
    expect(french.indexOf('Industrie')).toBe(french.indexOf(fr.ledger.tabs.escalations) - 1)
    expect(english.indexOf('Industry')).toBe(english.indexOf(en.ledger.tabs.pve) + 1)
    expect(english.indexOf('Industry')).toBe(english.indexOf(en.ledger.tabs.escalations) - 1)
  })

  it('shows the industry tab with the selected period when it is the active tab', async () => {
    route.query = { tab: 'industry' }

    const wrapper = await mountLedger('en')

    const industryTab = wrapper.findComponent(LedgerIndustryTab)
    expect(industryTab.exists()).toBe(true)
    expect(industryTab.props('selectedDays')).toBe(STORED_PERIOD_DAYS)
  })
})
