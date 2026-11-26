import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockTab from '@/components/planetary/PiStockTab.vue'
import { ValidationError } from '@/services/api'
import {
  AMARR_CANDIDATE,
  ASHAB_CANDIDATE,
  ASHAB_FORTIZAR_ID,
  ME_WITH_TWO_FAVORITES,
  SECOND_FAVORITE_HUB_ID,
  SETTINGS_WITH_AMARR_HOME,
  apiCalls,
  serve,
  type Answer,
} from '../../planetarySettingsFixtures'
import { COOLANT_ROW, PLASMA_ROW, ROBOTICS_ROW, STOCK_WITH_PURCHASES, stockOf } from '../../planetaryStockFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const STOCK_BEFORE = stockOf([ROBOTICS_ROW, PLASMA_ROW])
const STOCK_AFTER = stockOf([COOLANT_ROW])

async function mountStockTab(overrides: Record<string, Answer> = {}, stockAnswer: Answer = STOCK_WITH_PURCHASES) {
  setActivePinia(createPinia())
  let current = { ...SETTINGS_WITH_AMARR_HOME }
  serve({
    'GET /planetary/settings': () => current,
    'GET /planetary/settings/locations': { id: 'locations', locations: [AMARR_CANDIDATE, ASHAB_CANDIDATE] },
    'GET /me/settings': ME_WITH_TWO_FAVORITES,
    'GET /planetary/stock': stockAnswer,
    'PATCH /planetary/settings': (body) => (current = { ...current, ...body }),
    'PATCH /me/settings': (body) => ({ ...ME_WITH_TWO_FAVORITES, ...body }),
    ...overrides,
  })
  const wrapper = mount(PiStockTab, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function stockReads(): number {
  return apiCalls().filter(call => call.method === 'GET' && call.endpoint === '/planetary/stock').length
}

function rejectedLocation(): ValidationError {
  const message = 'This location is not known to any of your characters.'
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'homeLocationId', message }],
    description: message,
    detail: message,
  })
}

enableAutoUnmount(afterEach)

describe('PiStockTab', () => {
  it('reads the stock once when it is shown and lays out the banner, the grid, the purchase list and the settings in that order', async () => {
    const wrapper = await mountStockTab()

    expect(stockReads()).toBe(1)
    expect(wrapper.findAll('[data-testid^="stock-row-"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="stock-purchase-line-91031"]').exists()).toBe(true)
    const html = wrapper.html()
    const positions = ['pi-stock-banner', 'pi-stock-grid', 'pi-stock-purchase-list', 'stock-home-location'].map(marker => html.indexOf(marker))
    expect(positions.every(position => position >= 0)).toBe(true)
    expect([...positions].sort((left, right) => left - right)).toEqual(positions)
  })

  it('reads the stock again once after a saved setting, and once more after a saved hub, showing the new figures each time', async () => {
    let saved = false
    const wrapper = await mountStockTab(
      { 'PATCH /planetary/settings': (body) => { saved = true; return { ...SETTINGS_WITH_AMARR_HOME, ...body } } },
      () => (saved ? STOCK_AFTER : STOCK_BEFORE),
    )
    expect(stockReads()).toBe(1)
    expect(wrapper.findAll('[data-testid^="stock-row-"]')).toHaveLength(2)

    await wrapper.get('[data-testid="stock-home-location"]').setValue(String(ASHAB_FORTIZAR_ID))
    await flushPromises()

    expect(stockReads()).toBe(2)
    expect(wrapper.findAll('[data-testid^="stock-row-"]').map(row => row.attributes('data-testid'))).toEqual([`stock-row-${COOLANT_ROW.typeId}`])

    await wrapper.get('[data-testid="stock-hub"]').setValue(String(SECOND_FAVORITE_HUB_ID))
    await flushPromises()

    expect(stockReads()).toBe(3)
  })

  it('does not read the stock again when a setting is refused', async () => {
    const wrapper = await mountStockTab({ 'PATCH /planetary/settings': rejectedLocation() })
    expect(stockReads()).toBe(1)

    await wrapper.get('[data-testid="stock-home-location"]').setValue(String(ASHAB_FORTIZAR_ID))
    await flushPromises()

    expect(apiCalls().filter(call => call.method === 'PATCH')).toHaveLength(1)
    expect(stockReads()).toBe(1)
  })

  it('relays the request to open the planner from the grid', async () => {
    const wrapper = await mountStockTab()

    await wrapper.get(`[data-testid="stock-open-planner-${ROBOTICS_ROW.typeId}"]`).trigger('click')

    expect(wrapper.emitted('open-planner')).toHaveLength(1)
  })

  it('shows the load error without a grid row when the stock cannot be read', async () => {
    const wrapper = await mountStockTab({}, new Error('Network down'))

    expect(stockReads()).toBe(1)
    expect(wrapper.findAll('[data-testid^="stock-row-"]')).toHaveLength(0)
    expect(wrapper.text()).toContain(en.pi.stock.loadError)
  })
})
