import { defineComponent, h } from 'vue'
import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustryRevenue from '@/views/IndustryRevenue.vue'
import IndustryRevenueTab from '@/components/industry/IndustryRevenueTab.vue'
import IndustryModuleTabs from '@/components/industry-module/IndustryModuleTabs.vue'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const STORED_PERIOD_DAYS = 14

const MainLayoutStub = defineComponent({
  setup: (_, { slots }) => () => h('div', slots.default?.()),
})

async function mountView(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const wrapper = mount(IndustryRevenue, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: MainLayoutStub,
        IndustryModuleTabs: true,
        IndustryRevenueTab: true,
        LedgerPeriodSelector: true,
        RouterLink: RouterLinkStub,
      },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  localStorage.setItem('ledgerSelectedDays', String(STORED_PERIOD_DAYS))
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(null as never)
})

describe('IndustryRevenue view', () => {
  it('marks the revenue page of the industry module as current', async () => {
    const wrapper = await mountView()

    expect(wrapper.getComponent(IndustryModuleTabs).props('active')).toBe('revenue')
  })

  it('gives the revenue tab the stored period', async () => {
    const wrapper = await mountView()

    expect(wrapper.getComponent(IndustryRevenueTab).props('selectedDays')).toBe(STORED_PERIOD_DAYS)
  })

  it.each([
    ['en', 'Revenue'],
    ['fr', 'Revenus'],
  ] as const)('gives the page a screen reader title in %s', async (locale, title) => {
    const wrapper = await mountView(locale)

    expect(wrapper.get('h1').text()).toBe(title)
  })

  it('reads the dashboard once to know how far back the whole period goes', async () => {
    await mountView()

    expect(apiRequest).toHaveBeenCalledWith(`/ledger/dashboard?days=${STORED_PERIOD_DAYS}`)
  })
})
