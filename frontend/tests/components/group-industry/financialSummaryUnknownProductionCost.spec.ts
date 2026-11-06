import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution } from '@/stores/group-industry/types'

const SALE_WITH_AN_UNKNOWN_CONTRIBUTION: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 5_000,
  brokerFee: 100,
  salesTax: 150,
  netRevenue: 4_750,
  totalProjectCost: null,
  marginPercent: null,
  members: [{
    memberId: 'member-wenzel',
    characterName: 'Wenzel Roth',
    totalCostsEngaged: null,
    materialCosts: null,
    jobInstallCosts: null,
    bpcCosts: 0,
    lineRentalCosts: 0,
    sharePercent: null,
    profitPart: null,
    payoutTotal: null,
  }],
}

function mountFinancialSummary(distribution: Distribution): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

describe('FinancialSummary with an approved contribution without value', () => {
  it('shows an unknown production cost, profit and margin next to the known net revenue, never 0 nor NaN', () => {
    const wrapper = mountFinancialSummary(SALE_WITH_AN_UNKNOWN_CONTRIBUTION)

    const costLine = wrapper.findAll('div').find(line => line.text().startsWith(fr.groupIndustry.distribution.totalCost) && line.findAll('span').length === 2)
    expect(costLine?.find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.find('p.text-3xl').text()).toBe('—')
    expect(wrapper.text().replace(/[\u00a0\u202f]/g, ' ')).toContain('4,75 K')
    expect(wrapper.text()).not.toContain('NaN')
  })
})
