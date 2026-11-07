import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import LedgerIndustryTab from '@/components/ledger/LedgerIndustryTab.vue'
import { apiRequest } from '@/services/api'
import type { LedgerIndustry } from '@/stores/ledger'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const UNKNOWN_VALUE_TITLE_FR = 'Valeur inconnue'
const LONG_PRODUCT_NAME = 'Marauder Vargur Fleet Edition Special Production Batch Of Doom'
const MONTH_ROW = '[data-testid="industry-month-row"]'
const MONTH_BAR = '[data-testid="industry-month-bar"]'
const PRODUCT_ROW = '[data-testid="industry-product-row"]'
const EMPTY_STATE = '[data-testid="industry-empty"]'

const INDUSTRY: LedgerIndustry = {
  period: { from: '2026-10-08', to: '2026-11-07', days: 30 },
  totals: { profit: 130_000_000, projectCount: 4, unknownProjectCount: 1, capital: 600_000_000, slotHours: 160, iskPerHour: 812_500 },
  months: [
    { month: '2026-11', projectCount: 3, unknownProjectCount: 1, profit: 150_000_000, capital: 500_000_000, marginPercent: 30, slotHours: 150, iskPerHour: 1_000_000 },
    { month: '2026-10', projectCount: 1, unknownProjectCount: 0, profit: -20_000_000, capital: 100_000_000, marginPercent: -20, slotHours: 10, iskPerHour: -2_000_000 },
  ],
  products: [
    { typeId: 22456, typeName: 'Sabre', projectCount: 3, unknownProjectCount: 1, profit: 150_000_000 },
    { typeId: 12005, typeName: 'Ishtar', projectCount: 1, unknownProjectCount: 0, profit: -20_000_000 },
  ],
}

const INDUSTRY_WITHOUT_PROJECT: LedgerIndustry = {
  period: INDUSTRY.period,
  totals: { profit: 0, projectCount: 0, unknownProjectCount: 0, capital: 0, slotHours: null, iskPerHour: null },
  months: [],
  products: [],
}

async function mountTab(industry: LedgerIndustry, locale: 'en' | 'fr' = 'fr', selectedDays = 30): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(industry)
  const wrapper = mount(LedgerIndustryTab, {
    props: { selectedDays },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

function cellsOf(wrapper: VueWrapper, rowSelector: string, index: number) {
  return wrapper.findAll(rowSelector)[index].findAll('td')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('LedgerIndustryTab', () => {
  it('loads the period on mount and lists one row and one bar per month, most recent first, with a red bar for a loss', async () => {
    const wrapper = await mountTab(INDUSTRY)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith('/ledger/industry?days=30')
    expect(wrapper.findAll(MONTH_ROW)).toHaveLength(2)
    expect(cellsOf(wrapper, MONTH_ROW, 0).map(cell => cell.text()).filter((_, column) => [1, 2, 6].includes(column))).toEqual(['3', '150,00 M', '500,00 M'])
    expect(cellsOf(wrapper, MONTH_ROW, 1).map(cell => cell.text()).filter((_, column) => [1, 2, 6].includes(column))).toEqual(['1', '-20,00 M', '100,00 M'])
    const bars = wrapper.findAll(MONTH_BAR)
    expect(bars).toHaveLength(2)
    expect(bars[0].classes().some(cssClass => cssClass.includes('red'))).toBe(false)
    expect(bars[1].classes().some(cssClass => cssClass.includes('red'))).toBe(true)
  })

  it('shows an unknown margin, slot hours and ISK per hour as the unknown value and never as zero', async () => {
    const wrapper = await mountTab({
      ...INDUSTRY,
      months: [{ month: '2026-11', projectCount: 2, unknownProjectCount: 2, profit: 0, capital: 0, marginPercent: null, slotHours: null, iskPerHour: null }],
    })

    expect(wrapper.findAll(MONTH_ROW)).toHaveLength(1)
    for (const column of [3, 4, 5]) {
      const cell = cellsOf(wrapper, MONTH_ROW, 0)[column]
      expect(cell.text()).toBe('—')
      expect(cell.get('[title]').attributes('title')).toBe(UNKNOWN_VALUE_TITLE_FR)
    }
  })

  it('tells how many projects of a month have an unknown profit and are not counted', async () => {
    const wrapper = await mountTab(INDUSTRY)

    expect(wrapper.findAll(MONTH_ROW)).toHaveLength(2)
    expect(wrapper.findAll(MONTH_ROW)[0].text()).toContain('dont 1 à profit inconnu, non compté')
    expect(wrapper.findAll(MONTH_ROW)[1].text()).not.toContain('profit inconnu')
  })

  it('shows the empty state instead of tables when no project was completed over the period', async () => {
    const wrapper = await mountTab(INDUSTRY_WITHOUT_PROJECT)

    expect(wrapper.find(EMPTY_STATE).exists()).toBe(true)
    expect(wrapper.find(EMPTY_STATE).text()).toBe('Aucun projet terminé sur la période')
    expect(wrapper.findAll(MONTH_ROW)).toHaveLength(0)
    expect(wrapper.findAll(PRODUCT_ROW)).toHaveLength(0)
  })

  it('truncates a long product name with its full name as title and labels the multi-product line', async () => {
    const wrapper = await mountTab({
      ...INDUSTRY,
      products: [
        { typeId: 52250, typeName: LONG_PRODUCT_NAME, projectCount: 1, unknownProjectCount: 0, profit: 90_000_000 },
        { typeId: null, typeName: null, projectCount: 2, unknownProjectCount: 0, profit: 40_000_000 },
      ],
    })

    expect(wrapper.findAll(PRODUCT_ROW)).toHaveLength(2)
    const longNameCell = cellsOf(wrapper, PRODUCT_ROW, 0)[0]
    expect(longNameCell.get('[title]').attributes('title')).toBe(LONG_PRODUCT_NAME)
    expect(longNameCell.get('[title]').classes()).toContain('truncate')
    expect(cellsOf(wrapper, PRODUCT_ROW, 1)[0].text()).toMatch(/multi-produits/i)
    expect(cellsOf(wrapper, PRODUCT_ROW, 1)[2].text()).toBe('40,00 M')
  })

  it('reads the industry revenue again when the period changes', async () => {
    const wrapper = await mountTab(INDUSTRY)

    await wrapper.setProps({ selectedDays: 7 })
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)).toEqual(['/ledger/industry?days=30', '/ledger/industry?days=7'])
  })

  it('renders every text of the tab translated, in French and in English', async () => {
    for (const locale of ['fr', 'en'] as const) {
      const wrapper = await mountTab(INDUSTRY, locale)

      expect(wrapper.findAll(MONTH_ROW), locale).toHaveLength(2)
      expect(wrapper.findAll(PRODUCT_ROW), locale).toHaveLength(2)
      expect(wrapper.text(), locale).not.toContain('ledger.')
      wrapper.unmount()
    }
  })
})
