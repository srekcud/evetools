import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import PiProductionTable from '@/components/planetary/PiProductionTable.vue'
import type { ProductionTier } from '@/stores/planetary'

const EmptyView = { render: () => null }
const MARKET_LINK = '[data-testid="pi-product-market-link"]'

const COOLANT_TYPE_ID = 9832
const ROBOTICS_TYPE_ID = 9848

const P2_TIER: ProductionTier = {
  tier: 'P2',
  dailyIskValue: 4_200_000,
  items: [
    { typeId: COOLANT_TYPE_ID, typeName: 'Coolant', dailyQuantity: 40, dailyConsumedInternally: 0, dailySellableQuantity: 40, unitPrice: 3_100, dailyIskValue: 124_000, inputs: [] },
    { typeId: ROBOTICS_TYPE_ID, typeName: null, dailyQuantity: 12, dailyConsumedInternally: 0, dailySellableQuantity: 12, unitPrice: 6_400, dailyIskValue: 76_800, inputs: [] },
  ],
}

enableAutoUnmount(afterEach)

describe('PiProductionTable market links', () => {
  it('links every product of an expanded tier to its market detail and keeps its name', async () => {
    setActivePinia(createPinia())
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/market', name: 'market', component: EmptyView }] })
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(PiProductionTable, {
      global: { plugins: [i18n, router], stubs: { OpenInGameButton: true } },
      props: { production: [P2_TIER], totalDailyIsk: 4_200_000 },
    })
    await wrapper.find('tbody tr').trigger('click')

    expect(wrapper.findAll(MARKET_LINK).map(link => ({ href: link.attributes('href'), text: link.text() }))).toEqual([
      { href: `/market?tab=detail&typeId=${COOLANT_TYPE_ID}`, text: 'Coolant' },
      { href: `/market?tab=detail&typeId=${ROBOTICS_TYPE_ID}`, text: `Unknown name (#${ROBOTICS_TYPE_ID})` },
    ])
  })
})
