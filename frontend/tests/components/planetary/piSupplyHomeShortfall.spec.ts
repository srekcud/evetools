import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiSupplyTab from '@/components/planetary/PiSupplyTab.vue'
import { apiRequest } from '@/services/api'
import type { PlanetaryStock } from '@/stores/planetaryStock'
import type { PendingOutput } from '@/utils/planetaryPickup'
import { coolantFromAmarr, mechanicalPartsFromAmarr, pendingOutputOf, pendingStockResponse } from '../../planetaryPendingStockFixtures'
import { stockOf, stockRow } from '../../planetaryStockFixtures'
import { CONSUMER_ELECTRONICS, COOLANT, FERTILIZER, MECHANICAL_PARTS, referenceLaunchpad, supplyResponse } from '../../planetarySupplyFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const STOCK_ENDPOINT = '/planetary/stock'

const HOME_STOCK: PlanetaryStock = stockOf([
  stockRow(COOLANT, 'Coolant', { home: 2000 }),
  stockRow(MECHANICAL_PARTS, 'Mechanical Parts', { home: 9000 }),
  stockRow(CONSUMER_ELECTRONICS, 'Consumer Electronics', { home: null }),
])

async function mountTab(outputs: PendingOutput[], stock: PlanetaryStock | Error = HOME_STOCK) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint === '/planetary/input-needs') return supplyResponse([referenceLaunchpad()])
    if (endpoint === '/planetary/pending-stock') return pendingStockResponse(outputs)
    if (endpoint === STOCK_ENDPOINT) {
      if (stock instanceof Error) throw stock
      return stock
    }
    return new Promise(() => {})
  }) as never)
  const wrapper = mount(PiSupplyTab, { global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] } })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountTab>>

function cellOf(wrapper: Wrapper, typeId: number, column: 'home' | 'shortfall') {
  return wrapper.get(`[data-testid="pi-pickup-row"][data-type-id="${typeId}"] [data-testid="pi-pickup-${column}"]`)
}

function digitsOf(text: string): string {
  return text.replace(/[^\d]/g, '')
}

const OUTPUTS = [coolantFromAmarr({ quantity: 5500 }), mechanicalPartsFromAmarr({ quantity: 3000 })]

enableAutoUnmount(afterEach)

describe('PiSupplyPendingBlocks home stock and shortfall', () => {
  it('reads the stock once when the tab is shown', async () => {
    await mountTab(OUTPUTS)

    expect(vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === STOCK_ENDPOINT)).toHaveLength(1)
  })

  it('shows the home stock of each type after what is left to find, and the shortfall once it is used: 6020 left, 2000 at home, 4020 short', async () => {
    const wrapper = await mountTab(OUTPUTS)

    expect(digitsOf(cellOf(wrapper, COOLANT, 'home').text())).toBe('2000')
    expect(digitsOf(cellOf(wrapper, COOLANT, 'shortfall').text())).toBe('4020')
    expect(digitsOf(cellOf(wrapper, MECHANICAL_PARTS, 'home').text())).toBe('9000')
    expect(digitsOf(cellOf(wrapper, MECHANICAL_PARTS, 'shortfall').text())).toBe('0')
  })

  it('says unknown, never 0, for the home stock of a type without home figure and for a type absent from the stock', async () => {
    const wrapper = await mountTab(OUTPUTS)

    for (const typeId of [CONSUMER_ELECTRONICS, FERTILIZER]) {
      expect(cellOf(wrapper, typeId, 'home').findComponent(UnknownValue).exists()).toBe(true)
      expect(cellOf(wrapper, typeId, 'shortfall').findComponent(UnknownValue).exists()).toBe(true)
    }
  })

  it('shows a 0 shortfall when the colonies cover the need, even with an unknown home stock', async () => {
    const wrapper = await mountTab([pendingOutputOf({ typeId: FERTILIZER, typeName: 'Fertilizer', quantity: 12_000, volumeM3: 2280 })])

    expect(digitsOf(cellOf(wrapper, FERTILIZER, 'shortfall').text())).toBe('0')
    expect(cellOf(wrapper, FERTILIZER, 'shortfall').findComponent(UnknownValue).exists()).toBe(false)
  })

  it('keeps the rows and says unknown when the stock cannot be read', async () => {
    const wrapper = await mountTab(OUTPUTS, new Error('Network down'))

    expect(wrapper.findAll('[data-testid="pi-pickup-row"]')).toHaveLength(4)
    expect(cellOf(wrapper, COOLANT, 'home').findComponent(UnknownValue).exists()).toBe(true)
    expect(cellOf(wrapper, COOLANT, 'shortfall').findComponent(UnknownValue).exists()).toBe(true)
  })

  it('links a type with a shortfall to the Stock tab and emits open-stock when it is clicked', async () => {
    const wrapper = await mountTab(OUTPUTS)

    const link = wrapper.get(`[data-testid="pi-pickup-row"][data-type-id="${COOLANT}"] [data-testid="pi-pickup-open-stock"]`)
    expect(link.text()).toBe('Open Stock')
    await link.trigger('click')

    expect(wrapper.emitted('open-stock')).toHaveLength(1)
  })

  it('offers no Stock link on a type without shortfall or with an unknown one', async () => {
    const wrapper = await mountTab(OUTPUTS)

    for (const typeId of [MECHANICAL_PARTS, CONSUMER_ELECTRONICS]) {
      expect(wrapper.find(`[data-testid="pi-pickup-row"][data-type-id="${typeId}"] [data-testid="pi-pickup-open-stock"]`).exists()).toBe(false)
    }
  })
})
