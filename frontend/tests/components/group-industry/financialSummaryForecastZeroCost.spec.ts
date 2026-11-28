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

function forecastOf(totals: Partial<SellForecast['totals']> = {}): SellForecast {
  return {
    projectId: PROJECT_ID,
    sellStructure: { id: 1, name: 'Trade Hub Annex' },
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
  }
}

function mountWith(distribution: Distribution, forecast: SellForecast): VueWrapper {
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

describe('FinancialSummary forecast cost rows', () => {
  it('shows a zero cost left to produce without a sign', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf({ remainingCost: 0 }))

    expect(cell(wrapper, 'jita', 'remaining')).toBe('0')
    expect(cell(wrapper, 'structure', 'remaining')).toBe('0')
  })

  it('shows a zero cost incurred without a sign', () => {
    const wrapper = mountWith(profitableDistribution({ totalProjectCost: 0 }), forecastOf())

    expect(cell(wrapper, 'jita', 'incurred')).toBe('0')
    expect(cell(wrapper, 'structure', 'incurred')).toBe('0')
  })

  it('keeps the minus sign on a non-zero cost', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf())

    expect(cell(wrapper, 'jita', 'incurred')).toBe('-1.20 M')
    expect(cell(wrapper, 'jita', 'remaining')).toBe('-1.00 M')
  })

  it('shows an unknown cost as unknown, never as a signed zero', () => {
    const wrapper = mountWith(profitableDistribution(), forecastOf({ remainingCost: null }))

    expect(cell(wrapper, 'jita', 'remaining')).toBe('—')
  })
})
