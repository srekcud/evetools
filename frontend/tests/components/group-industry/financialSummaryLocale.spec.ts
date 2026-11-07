import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { Distribution, GroupProject } from '@/stores/group-industry/types'
import { expectNoneOf, expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const BROKER_FEE_PERCENT = 3
const SALES_TAX_PERCENT = 3.6

const DISTRIBUTION: Distribution = {
  id: 'project-218',
  totalRevenue: 3_000_000,
  brokerFee: 90_000,
  salesTax: 108_000,
  netRevenue: 2_802_000,
  totalProjectCost: 1_500_000,
  marginPercent: 86.8,
  members: [{
    memberId: 'member-218',
    characterName: 'Torvald Ek',
    totalCostsEngaged: 1_500_000,
    materialCosts: 1_000_000,
    jobInstallCosts: 300_000,
    bpcCosts: 100_000,
    lineRentalCosts: 100_000,
    courierCosts: 0,
    sharePercent: 100,
    profitPart: 1_302_000,
    payoutTotal: 2_802_000,
  }],
}

const FORMER_HARDCODED_TEXTS = [
  'Financial Summary',
  'Total Revenue',
  'Broker Fee',
  'Sales Tax',
  'Net Revenue',
  'Total Project Cost',
  'Materials',
  'Job Install + Line Rental',
  'Margin:',
]

function mountFinancialSummary(locale: TestLocale, project: Partial<GroupProject>) {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = DISTRIBUTION
  useGroupProjectStore().currentProject = project as GroupProject
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

function withPercent(locale: TestLocale, key: string, percent: number): string {
  return messageOf(locale, key).replace('{percent}', String(percent))
}

enableAutoUnmount(afterEach)

describe('FinancialSummary locale', () => {
  it.each(TEST_LOCALES)('shows only %s texts with the fee rates of the project', (locale) => {
    const text = mountFinancialSummary(locale, { brokerFeePercent: BROKER_FEE_PERCENT, salesTaxPercent: SALES_TAX_PERCENT }).text()

    expectOnlyLocaleTexts(text, locale, [
      'groupIndustry.financialSummary.title',
      'groupIndustry.distribution.totalRevenue',
      'groupIndustry.distribution.netRevenue',
      'groupIndustry.distribution.totalCost',
      'groupIndustry.financialSummary.materials',
      'groupIndustry.financialSummary.jobInstallAndLineRental',
      'groupIndustry.financialSummary.profit',
      'groupIndustry.financialSummary.marginLabel',
    ])
    expect(text).toContain(withPercent(locale, 'groupIndustry.financialSummary.brokerFeeWithRate', BROKER_FEE_PERCENT))
    expect(text).toContain(withPercent(locale, 'groupIndustry.financialSummary.salesTaxWithRate', SALES_TAX_PERCENT))
    if (locale === 'fr') expectNoneOf(text, FORMER_HARDCODED_TEXTS)
  })

  it.each(TEST_LOCALES)('shows only %s fee labels when the project has no fee rates', (locale) => {
    const text = mountFinancialSummary(locale, {}).text()

    expectOnlyLocaleTexts(text, locale, ['groupIndustry.distribution.brokerFee', 'groupIndustry.distribution.salesTax'])
    expect(text).not.toContain('%)')
  })
})
