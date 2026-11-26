import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiStockPurchaseList from '@/components/planetary/PiStockPurchaseList.vue'
import { usePlanetaryStockStore, type PlanetaryStock } from '@/stores/planetaryStock'
import {
  COOLANT_ROW,
  PLASMA_ROW,
  ROBOTICS_ROW,
  STOCK_WITH_PURCHASES,
  UNKNOWN_EXTRACTION_ROW,
  stockOf,
  stockRow,
} from '../../planetaryStockFixtures'

function mountList(stock: PlanetaryStock) {
  setActivePinia(createPinia())
  usePlanetaryStockStore().stock = stock
  return mount(PiStockPurchaseList, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

function lineIds(wrapper: ReturnType<typeof mountList>): string[] {
  return wrapper.findAll('[data-testid^="stock-purchase-line-"]').map(line => String(line.attributes('data-testid')))
}

function digitsOf(text: string): string {
  return text.replace(/[^0-9]/g, '')
}

let writeText: ReturnType<typeof vi.fn>

beforeEach(() => {
  writeText = vi.fn().mockResolvedValue(undefined)
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
})

enableAutoUnmount(afterEach)

describe('PiStockPurchaseList', () => {
  it('lists the types to buy with their quantity and the total volume, without the empty message', async () => {
    const wrapper = mountList(stockOf([ROBOTICS_ROW, PLASMA_ROW, COOLANT_ROW, UNKNOWN_EXTRACTION_ROW]))

    expect(lineIds(wrapper)).toEqual([`stock-purchase-line-${ROBOTICS_ROW.typeId}`, `stock-purchase-line-${PLASMA_ROW.typeId}`])
    const robotics = wrapper.get(`[data-testid="stock-purchase-line-${ROBOTICS_ROW.typeId}"]`).text()
    const plasma = wrapper.get(`[data-testid="stock-purchase-line-${PLASMA_ROW.typeId}"]`).text()
    expect(robotics).toContain('Robotics')
    expect(digitsOf(robotics)).toBe('1001')
    expect(plasma).toContain('Plasma')
    expect(digitsOf(plasma)).toBe('600')
    expect(wrapper.get('[data-testid="stock-purchase-volume"]').text()).toContain('650.5')
    expect(wrapper.get('[data-testid="stock-purchase-volume"]').findComponent(UnknownValue).exists()).toBe(false)
    expect(wrapper.find('[data-testid="stock-purchase-empty"]').exists()).toBe(false)
  })

  it('shows the total volume as unknown when a line of the list has no volume', async () => {
    const withoutVolume = stockRow(ROBOTICS_ROW.typeId, 'Robotics', { toBuy: 1001, volumePerUnit: null })
    const wrapper = mountList(stockOf([withoutVolume, PLASMA_ROW]))

    expect(lineIds(wrapper)).toHaveLength(2)
    expect(wrapper.get('[data-testid="stock-purchase-volume"]').findComponent(UnknownValue).exists()).toBe(true)
    expect(wrapper.get('[data-testid="stock-purchase-volume"]').text()).not.toContain('150')
  })

  it('copies one tab separated line per type without thousands separator, then confirms the copy or reports its failure', async () => {
    const wrapper = mountList(STOCK_WITH_PURCHASES)

    await wrapper.get('[data-testid="stock-purchase-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    expect(writeText).toHaveBeenCalledWith('Robotics\t1001\nPlasma\t600')
    expect(wrapper.text()).toContain(en.pi.stock.purchase.copied)
    expect(wrapper.text()).not.toContain(en.pi.stock.purchase.copyFailed)

    writeText.mockRejectedValueOnce(new Error('Denied'))
    await wrapper.get('[data-testid="stock-purchase-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain(en.pi.stock.purchase.copyFailed)
    expect(wrapper.text()).not.toContain(en.pi.stock.purchase.copied)
  })

  it('shows the nothing-to-buy message and no line when no row is to buy', async () => {
    const wrapper = mountList(stockOf([COOLANT_ROW, UNKNOWN_EXTRACTION_ROW]))

    expect(wrapper.get('[data-testid="stock-purchase-empty"]').text()).toBe(en.pi.stock.purchase.empty)
    expect(lineIds(wrapper)).toEqual([])
  })
})
