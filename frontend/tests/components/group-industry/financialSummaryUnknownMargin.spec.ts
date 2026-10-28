import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution } from '@/stores/group-industry/types'

const UNDISTRIBUTABLE_SALE: Distribution = {
  id: 'project-sabre',
  totalRevenue: 1_000_000,
  brokerFee: 36_000,
  salesTax: 36_000,
  netRevenue: 928_000,
  totalProjectCost: 0,
  marginPercent: null,
  members: [{
    memberId: 'member-aria',
    characterName: 'Aria Solberg',
    totalCostsEngaged: 0,
    materialCosts: 0,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    sharePercent: null,
    profitPart: null,
    payoutTotal: null,
  }],
}

const PROFITABLE_SALE: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 2_000_000,
  brokerFee: 72_000,
  salesTax: 72_000,
  netRevenue: 1_856_000,
  totalProjectCost: 1_172_498.42,
  marginPercent: 58.3,
  members: [{
    memberId: 'member-brann',
    characterName: 'Brann Holt',
    totalCostsEngaged: 1_172_498.42,
    materialCosts: 1_172_498.42,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    sharePercent: 100,
    profitPart: 683_501.58,
    payoutTotal: 1_856_000,
  }],
}

function mountFinancialSummary(distribution: Distribution, locale: 'en' | 'fr'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

function marginLine(wrapper: VueWrapper): DOMWrapper<Element> {
  const line = wrapper.findAll('p').find(paragraph => paragraph.text().startsWith('Margin:'))
  if (line === undefined) throw new Error('margin line not found')
  return line
}

describe('FinancialSummary margin', () => {
  it('shows an unknown margin with the unknown value tooltip when the distribution is impossible', () => {
    const wrapper = mountFinancialSummary(UNDISTRIBUTABLE_SALE, 'fr')

    const margin = marginLine(wrapper)
    expect(margin.text()).toBe('Margin: —')
    expect(margin.find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.text()).not.toContain('0.0%')
    expect(wrapper.text()).not.toContain('0,0%')
  })

  it('still shows the known profit of an impossible distribution', () => {
    const wrapper = mountFinancialSummary(UNDISTRIBUTABLE_SALE, 'fr')

    expect(wrapper.text().replace(/[  ]/g, ' ')).toContain('+928,00 K')
  })

  it('shows an unknown margin in English', () => {
    const wrapper = mountFinancialSummary(UNDISTRIBUTABLE_SALE, 'en')

    const margin = marginLine(wrapper)
    expect(margin.text()).toBe('Margin: —')
    expect(margin.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.text()).toContain('+928.00 K')
  })

  it('shows a known margin as a signed percentage', () => {
    const wrapper = mountFinancialSummary(PROFITABLE_SALE, 'en')

    expect(marginLine(wrapper).text()).toBe('Margin: +58.3%')
    expect(wrapper.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(false)
  })
})
