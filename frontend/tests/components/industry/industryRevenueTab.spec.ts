import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustryRevenueTab from '@/components/industry/IndustryRevenueTab.vue'
import { apiRequest } from '@/services/api'
import type { LedgerIndustry } from '@/stores/ledger'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const UNKNOWN_VALUE_TITLE_FR = 'Valeur inconnue'
const LONG_PRODUCT_NAME = 'Marauder Vargur Fleet Edition Special Production Batch Of Doom'
const MONTH_ROW = '[data-testid="industry-month-row"]'
const NET_VALUE = '[data-testid="net-value"]'
const TODO_PANEL = '[data-testid="revenue-todo-panel"]'
const PRODUCT_ROW = '[data-testid="industry-product-row"]'
const EMPTY_STATE = '[data-testid="industry-empty"]'

const INDUSTRY: LedgerIndustry = {
  period: { from: '2026-10-08', to: '2026-11-07', days: 30 },
  totals: { profit: 130_000_000, projectCount: 4, unknownProjectCount: 1, capital: 600_000_000, slotHours: 160, iskPerHour: 812_500, change: 12.5 },
  daily: { '2026-11-03': 150_000_000, '2026-10-20': -20_000_000 },
  months: [
    { month: '2026-11', projectCount: 3, unknownProjectCount: 1, profit: 150_000_000, capital: 500_000_000, marginPercent: 30, slotHours: 150, iskPerHour: 1_000_000 },
    { month: '2026-10', projectCount: 1, unknownProjectCount: 0, profit: -20_000_000, capital: 100_000_000, marginPercent: -20, slotHours: 10, iskPerHour: -2_000_000 },
  ],
  products: [
    { typeId: 22456, typeName: 'Sabre', projectCount: 3, unknownProjectCount: 1, profit: 150_000_000, capital: 500_000_000, marginPercent: 30 },
    { typeId: 12005, typeName: 'Ishtar', projectCount: 1, unknownProjectCount: 0, profit: -20_000_000, capital: 100_000_000, marginPercent: -20 },
  ],
}

const INDUSTRY_WITHOUT_PROJECT: LedgerIndustry = {
  period: INDUSTRY.period,
  totals: { profit: 0, projectCount: 0, unknownProjectCount: 0, capital: 0, slotHours: null, iskPerHour: null, change: null },
  daily: {},
  months: [],
  products: [],
}

async function mountTab(industry: LedgerIndustry, locale: 'en' | 'fr' = 'fr', selectedDays = 30): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue(industry)
  const wrapper = mount(IndustryRevenueTab, {
    props: { selectedDays },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })], stubs: { RouterLink: RouterLinkStub } },
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

describe('IndustryRevenueTab', () => {
  it('loads the period on mount and lists one row per month, most recent first', async () => {
    const wrapper = await mountTab(INDUSTRY)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith('/ledger/industry?days=30')
    expect(wrapper.findAll(MONTH_ROW)).toHaveLength(2)
    expect(cellsOf(wrapper, MONTH_ROW, 0).map(cell => cell.text()).filter((_, column) => [1, 2, 6].includes(column))).toEqual(['3', '150,00 M', '500,00 M'])
    expect(cellsOf(wrapper, MONTH_ROW, 1).map(cell => cell.text()).filter((_, column) => [1, 2, 6].includes(column))).toEqual(['1', '-20,00 M', '100,00 M'])
  })

  it('shows the profit on the revenue grid with its change and the products as contributors, instead of cards and bars', async () => {
    const wrapper = await mountTab(INDUSTRY)

    expect(wrapper.get('[data-cell="profit"]').text()).toBe('130,00 M')
    expect(wrapper.get(NET_VALUE).text()).toBe('130,00 M')
    expect(wrapper.get('[data-change="profit"]').text()).toBe('▲ 13 %')
    expect(wrapper.text()).toContain('Sabre')
    expect(wrapper.find('[data-testid="industry-month-bar"]').exists()).toBe(false)
  })

  it('keeps the projects with an unknown profit outside the net, behind a badge, and offers to open the projects', async () => {
    const wrapper = await mountTab(INDUSTRY)

    expect(wrapper.get('[data-cell="unknownProfit"] div').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-cell="unknownProfit"]').text()).toBe('—')
    expect(wrapper.get('[data-column="unknownProfit"] [data-testid="header-badge"]').text()).toBe('1')
    expect(wrapper.get(TODO_PANEL).text()).toContain('1 projet à profit inconnu')
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'industry-projects' })
    expect(wrapper.getComponent(RouterLinkStub).text()).toBe('Ouvrir les projets')
  })

  it('shows no unknown-profit column and no To fix panel when every profit is known', async () => {
    const wrapper = await mountTab({ ...INDUSTRY, totals: { ...INDUSTRY.totals, unknownProjectCount: 0 } })

    expect(wrapper.find('[data-column="unknownProfit"]').exists()).toBe(false)
    expect(wrapper.find(TODO_PANEL).exists()).toBe(false)
  })

  it('shows the profit as unknown, never 0, when every project has an unknown profit', async () => {
    const wrapper = await mountTab({
      ...INDUSTRY,
      totals: { profit: 0, projectCount: 2, unknownProjectCount: 2, capital: 0, slotHours: null, iskPerHour: null, change: null },
      daily: {},
      months: [{ month: '2026-11', projectCount: 2, unknownProjectCount: 2, profit: 0, capital: 0, marginPercent: null, slotHours: null, iskPerHour: null }],
      products: [{ typeId: 35683, typeName: 'Hecate', projectCount: 2, unknownProjectCount: 2, profit: 0, capital: 0, marginPercent: null }],
    })

    expect(wrapper.get('[data-cell="profit"] div').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-cell="profit"]').text()).toBe('—')
    expect(wrapper.get(NET_VALUE).text()).toBe('—')
    expect(wrapper.get(NET_VALUE).text()).not.toContain('0')
  })

  it('lists the capital, the profit and the margin of each product, an unknown figure as the unknown value', async () => {
    const wrapper = await mountTab({
      ...INDUSTRY,
      products: [
        ...INDUSTRY.products,
        { typeId: 35683, typeName: 'Hecate', projectCount: 1, unknownProjectCount: 1, profit: 0, capital: 0, marginPercent: null },
        { typeId: 11567, typeName: 'Avatar', projectCount: 1, unknownProjectCount: 0, profit: 5_000_000, capital: 0, marginPercent: null },
      ],
    })

    expect(cellsOf(wrapper, PRODUCT_ROW, 0).map(cell => cell.text())).toEqual(['Sabre', '3 dont 1 à profit inconnu, non compté', '500,00 M', '150,00 M', '30%'])
    expect(cellsOf(wrapper, PRODUCT_ROW, 1).map(cell => cell.text()).slice(2)).toEqual(['100,00 M', '-20,00 M', '-20%'])
    expect(cellsOf(wrapper, PRODUCT_ROW, 2).map(cell => cell.text()).slice(2)).toEqual(['—', '—', '—'])
    expect(cellsOf(wrapper, PRODUCT_ROW, 3).map(cell => cell.text()).slice(2)).toEqual(['0', '5,00 M', '—'])
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
        { typeId: 52250, typeName: LONG_PRODUCT_NAME, projectCount: 1, unknownProjectCount: 0, profit: 90_000_000, capital: 300_000_000, marginPercent: 30 },
        { typeId: null, typeName: null, projectCount: 2, unknownProjectCount: 0, profit: 40_000_000, capital: 100_000_000, marginPercent: 40 },
      ],
    })

    expect(wrapper.findAll(PRODUCT_ROW)).toHaveLength(2)
    const longNameCell = cellsOf(wrapper, PRODUCT_ROW, 0)[0]
    expect(longNameCell.get('[title]').attributes('title')).toBe(LONG_PRODUCT_NAME)
    expect(longNameCell.get('[title]').classes()).toContain('truncate')
    expect(cellsOf(wrapper, PRODUCT_ROW, 1)[0].text()).toMatch(/multi-produits/i)
    expect(cellsOf(wrapper, PRODUCT_ROW, 1)[3].text()).toBe('40,00 M')
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
