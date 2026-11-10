import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { Distribution, GroupProject, MemberDistribution } from '@/stores/group-industry/types'

function member(costs: Partial<MemberDistribution>): MemberDistribution {
  return {
    memberId: 'member-brann',
    characterName: 'Brann Holt',
    totalCostsEngaged: 9_280_000,
    materialCosts: 8_000_000,
    jobInstallCosts: 1_280_000,
    bpcCosts: 0,
    lineRentalCosts: 0,
    courierCosts: 0,
    sharePercent: 100,
    profitPart: null,
    payoutTotal: null,
    ...costs,
  }
}

const PERSONAL_USE_DISTRIBUTION: Distribution = {
  id: 'project-hammerhead',
  totalRevenue: 0,
  brokerFee: 0,
  salesTax: 0,
  netRevenue: null,
  totalProjectCost: 9_280_000,
  marginPercent: null,
  awaitingSales: true,
  unsoldProducts: [],
  projectedMarginPercent: null,
  breakEvenPerUnit: null,
  breakEvenUnknownReason: 'fees_unknown',
  costPerUnit: 92_800,
  costPerUnitUnknownReason: null,
  members: [member({})],
}

function mountCascade(personalUse: boolean, distribution: Distribution = PERSONAL_USE_DISTRIBUTION, locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().currentProject = { id: 'project-hammerhead', personalUse } as GroupProject
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(FinancialCascade, { global: { plugins: [i18n] } })
}

function text(wrapper: VueWrapper, testId: string): string {
  return wrapper.get(`[data-testid="${testId}"]`).text().replace(/[\u00a0\u202f]/g, ' ')
}

describe('FinancialCascade personal use', () => {
  it('shows the costs and the cost per unit instead of the revenue, the fees, the profit and the break-even', () => {
    const wrapper = mountCascade(true)

    expect(text(wrapper, 'personal-use-cost-per-unit')).toContain('92.80 K')
    expect(text(wrapper, 'cascade-step-materials')).toContain('-8.00 M')
    expect(text(wrapper, 'cascade-step-jobInstall')).toContain('-1.28 M')
    expect(text(wrapper, 'personal-use-total-cost')).toContain('9.28 M')
    for (const salesStep of ['revenue', 'brokerFee', 'salesTax', 'profit']) {
      expect(wrapper.find(`[data-testid="cascade-step-${salesStep}"]`).exists()).toBe(false)
    }
    expect(wrapper.find('[data-testid="break-even"]').exists()).toBe(false)
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('explains an unknown cost per unit by its cause instead of showing a value', () => {
    const wrapper = mountCascade(true, { ...PERSONAL_USE_DISTRIBUTION, costPerUnit: null, costPerUnitUnknownReason: 'jobs_not_delivered' })

    expect(wrapper.get('[data-testid="personal-use-unknown"]').text()).toContain('Final jobs are not all delivered yet')
    expect(wrapper.find('[data-testid="personal-use-cost-per-unit"]').exists()).toBe(false)
  })

  it('keeps the revenue, the profit and the break-even of a project meant to be sold', () => {
    const wrapper = mountCascade(false, { ...PERSONAL_USE_DISTRIBUTION, breakEvenPerUnit: 96_666.67, breakEvenUnknownReason: null })

    expect(wrapper.find('[data-testid="cascade-step-revenue"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cascade-step-profit"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="break-even-value"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="personal-use-cost-per-unit"]').exists()).toBe(false)
  })

  it('labels the personal use variant in French', () => {
    const wrapper = mountCascade(true, PERSONAL_USE_DISTRIBUTION, 'fr')

    expect(wrapper.get('[data-testid="personal-use-cost-per-unit"]').element.parentElement?.textContent).toContain('Coût par unité')
  })
})
