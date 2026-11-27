import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import { useGroupSellForecastStore } from '@/stores/group-industry/sellForecast'
import type { GroupProject, SellForecast } from '@/stores/group-industry/types'
import { profitableDistribution } from './projectDiscordRecapFixtures'

const PROJECT_ID = 'project-sabre'
const LONG_STRUCTURE_NAME = 'Long Structure Name - Regional Trade Hub Annex Fortizar'
const FORECAST_FIELDS = ['sold', 'left', 'incurred', 'remaining', 'profit', 'margin']
const MARKETS = ['structure', 'jita'] as const

function forecastWithStructure(name: string): SellForecast {
  return {
    projectId: PROJECT_ID,
    sellStructure: { id: 1, name },
    products: [{ typeId: 1, typeName: 'Barrage L', quantity: 10, structure: null, jita: { name: 'Jita', unitPrice: 1, coveragePercent: null, feeRate: 0, netUnitPrice: 1, netRevenue: 1, daysToSell: 1 } }],
    totals: {
      structureNetRevenue: 239_510_000,
      jitaNetRevenue: 4_000_000,
      structureUnknownProductCount: 0,
      jitaUnknownProductCount: 0,
      remainingCost: 1_000_000,
      remainingCostUnknownLineCount: 0,
      structureProfit: null,
      jitaProfit: null,
    },
  }
}

function mountWithStructure(name: string): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = profitableDistribution()
  useGroupProjectStore().currentProject = { id: PROJECT_ID } as GroupProject
  useGroupSellForecastStore().forecast = forecastWithStructure(name)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

describe('FinancialSummary forecast grid with a long structure name', () => {
  it.each(MARKETS.flatMap(market => FORECAST_FIELDS.map(field => ({ market, field }))))('keeps the $market $field amount on one line', ({ market, field }) => {
    const wrapper = mountWithStructure(LONG_STRUCTURE_NAME)

    expect(wrapper.get(`[data-testid="forecast-${market}-${field}"]`).classes()).toContain('whitespace-nowrap')
  })

  it('lets the long structure name wrap in its column header', () => {
    const wrapper = mountWithStructure(LONG_STRUCTURE_NAME)
    const header = wrapper.findAll('[data-testid="forecast-profit"] th').find(candidate => candidate.text() === LONG_STRUCTURE_NAME)

    expect(header).toBeDefined()
    expect(header?.classes()).not.toContain('whitespace-nowrap')
  })
})
