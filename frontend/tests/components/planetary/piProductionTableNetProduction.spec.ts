import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import PiProductionTable from '@/components/planetary/PiProductionTable.vue'
import type { ProductionTier } from '@/stores/planetary'

const EmptyView = { render: () => null }
const INTERNAL_USE = '[data-testid="pi-product-internal"]'

const WATER_TYPE_ID = 3645
const COOLANT_TYPE_ID = 9832

const P1_TIER: ProductionTier = {
  tier: 'P1',
  dailyIskValue: 395_875,
  items: [
    { typeId: WATER_TYPE_ID, typeName: 'Water', dailyQuantity: 3840, dailyConsumedInternally: 2880, dailySellableQuantity: 960, unitPrice: 412.37, dailyIskValue: 395_875, inputs: [] },
    { typeId: COOLANT_TYPE_ID, typeName: 'Coolant', dailyQuantity: 120, dailyConsumedInternally: 0, dailySellableQuantity: 120, unitPrice: 11_873, dailyIskValue: 1_424_808, inputs: [] },
  ],
}

enableAutoUnmount(afterEach)

function mountTable() {
  setActivePinia(createPinia())
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/market', name: 'market', component: EmptyView }] })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PiProductionTable, {
    global: { plugins: [i18n, router], stubs: { OpenInGameButton: true } },
    props: { production: [P1_TIER], totalDailyIsk: 395_875 },
  })
}

describe('PiProductionTable net production', () => {
  it('shows the volume used by the own factories and the sellable volume next to a consumed product only', async () => {
    const wrapper = mountTable()
    await wrapper.find('tbody tr').trigger('click')

    const internalUse = wrapper.findAll(INTERNAL_USE)
    expect(internalUse).toHaveLength(1)
    expect(internalUse[0].text()).toBe('2,880/d used in-house, 960/d sellable')
  })

  it('states that the value only counts what the own factories do not consume', () => {
    const wrapper = mountTable()

    expect(wrapper.text()).toContain('surplus not consumed by your own factories')
  })
})
