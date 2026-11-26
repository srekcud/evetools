import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'
import type { Distribution, GroupProject, SellForecast } from '@/stores/group-industry/types'
import { profitableDistribution } from './projectDiscordRecapFixtures'

const PROJECT_ID = 'project-sabre'
const FORECAST_BOX = '[data-testid="forecast-profit"]'
const SELL_STRUCTURE = { id: 1_040_804_972_352, name: 'Perimeter - Tranquility Trade Center' }

function forecastOf(overrides: Partial<SellForecast> = {}, totals: Partial<SellForecast['totals']> = {}): SellForecast {
  return {
    projectId: PROJECT_ID,
    sellStructure: SELL_STRUCTURE,
    products: [{ typeId: 1, typeName: 'Barrage L', quantity: 10, structure: null, jita: { name: 'Jita', unitPrice: 1, coveragePercent: null, feeRate: 0, netUnitPrice: 1, netRevenue: 1, daysToSell: 1 } }],
    totals: {
      structureNetRevenue: 5_000_000,
      jitaNetRevenue: 4_000_000,
      structureUnknownProductCount: 0,
      jitaUnknownProductCount: 0,
      remainingCost: 1_000_000,
      remainingCostUnknownLineCount: 0,
      structureProfit: null,
      jitaProfit: null,
      ...totals,
    },
    ...overrides,
  }
}

function mountWith(distribution: Distribution, forecast: SellForecast | null): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  useGroupProjectStore().currentProject = { id: PROJECT_ID } as GroupProject
  useGroupSellForecastStore().forecast = forecast
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

function cell(wrapper: VueWrapper, market: 'structure' | 'jita', field: string): string {
  return wrapper.get(`[data-testid="forecast-${market}-${field}"]`).text()
}

describe('FinancialSummary forecast profit', () => {
  it('shows the forecast of the whole project once per market, side by side', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf())

    expect(wrapper.get(FORECAST_BOX).text()).toContain('Forecast profit')
    expect(wrapper.get(FORECAST_BOX).text()).toContain(SELL_STRUCTURE.name)
    expect(cell(wrapper, 'structure', 'sold')).toContain('1.86 M')
    expect(cell(wrapper, 'structure', 'left')).toContain('5.00 M')
    expect(cell(wrapper, 'structure', 'incurred')).toContain('1.20 M')
    expect(cell(wrapper, 'structure', 'remaining')).toContain('1.00 M')
    expect(cell(wrapper, 'structure', 'profit')).toContain('4.66 M')
    expect(cell(wrapper, 'structure', 'margin')).toContain('+211.8%')
    expect(cell(wrapper, 'jita', 'left')).toContain('4.00 M')
    expect(cell(wrapper, 'jita', 'profit')).toContain('3.66 M')
    expect(cell(wrapper, 'jita', 'margin')).toContain('+166.4%')
  })

  it('shows only the Jita column without a sell structure', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf({ sellStructure: null }, { structureNetRevenue: null }))

    expect(wrapper.find('[data-testid="forecast-structure-profit"]').exists()).toBe(false)
    expect(cell(wrapper, 'jita', 'profit')).toContain('3.66 M')
  })

  it('shows an unknown profit, never 0, when a term of the column is unknown', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf({}, { remainingCost: null }))

    const profit = wrapper.get('[data-testid="forecast-jita-profit"]')
    expect(profit.text()).toBe('—')
    expect(profit.find('[title="Unknown value"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="forecast-jita-margin"]').text()).toBe('—')
  })

  it('shows the forecast while no sale is recorded yet', () => {
    const wrapper = mountWith(profitableDistribution({ awaitingSales: true, netRevenue: null, totalRevenue: 0, marginPercent: null }), forecastOf())

    expect(cell(wrapper, 'jita', 'sold')).toContain('0')
    expect(cell(wrapper, 'jita', 'profit')).toContain('1.80 M')
  })

  it('falls back to the realised profit labelled Profit once nothing is left to sell', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf({ products: [] }, { structureNetRevenue: null, jitaNetRevenue: null }))

    expect(wrapper.find(FORECAST_BOX).exists()).toBe(false)
    expect(wrapper.text()).toContain('+660.00 K')
    expect(wrapper.text()).toContain('Profit')
    expect(wrapper.text()).not.toContain('Provisional profit')
  })

  it('keeps the realised profit while the forecast is not loaded', () => {
    const wrapper = mountWith(profitableDistribution(), null)

    expect(wrapper.find(FORECAST_BOX).exists()).toBe(false)
    expect(wrapper.text()).toContain('+660.00 K')
  })

  it('ignores the forecast of another project', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf({ projectId: 'another-project' }))

    expect(wrapper.find(FORECAST_BOX).exists()).toBe(false)
  })

  it('names the sell forecast row margin on what is left to sell, so the page shows one figure called profit', () => {
    expect(en.groupIndustry.sellForecast.plannedProfit).toBe('Margin on what is left to sell')
  })
})
