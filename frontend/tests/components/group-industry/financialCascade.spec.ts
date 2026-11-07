import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, MemberDistribution } from '@/stores/group-industry/types'

function member(costs: Partial<MemberDistribution>): MemberDistribution {
  return {
    memberId: 'member-brann',
    characterName: 'Brann Holt',
    totalCostsEngaged: null,
    materialCosts: 0,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    courierCosts: 0,
    sharePercent: null,
    profitPart: null,
    payoutTotal: null,
    ...costs,
  }
}

const PROFITABLE_SALE: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 2_000_000,
  brokerFee: 72_000,
  salesTax: 72_000,
  netRevenue: 1_856_000,
  totalProjectCost: 1_172_498.42,
  marginPercent: 58.3,
  members: [
    member({ materialCosts: 700_000, jobInstallCosts: 100_000, lineRentalCosts: 20_000 }),
    member({ memberId: 'member-aria', materialCosts: 200_000, jobInstallCosts: 100_000, lineRentalCosts: 52_498.42 }),
  ],
}

const LOSING_SALE: Distribution = {
  id: 'project-sabre',
  totalRevenue: 1_000_000,
  brokerFee: 36_000,
  salesTax: 36_000,
  netRevenue: 928_000,
  totalProjectCost: 1_300_000,
  marginPercent: -28.6,
  members: [member({ materialCosts: 1_000_000, jobInstallCosts: 200_000, bpcCosts: 100_000 })],
}

const UNKNOWN_FEES_SALE: Distribution = {
  ...PROFITABLE_SALE,
  brokerFee: null,
  salesTax: null,
  netRevenue: null,
  marginPercent: null,
}

const UNKNOWN_MATERIAL_PRICE_SALE: Distribution = {
  ...PROFITABLE_SALE,
  totalProjectCost: null,
  marginPercent: null,
  members: [member({ materialCosts: null, jobInstallCosts: 100_000, lineRentalCosts: 20_000 })],
}

function mountCascade(distribution: Distribution | null, locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(FinancialCascade, { global: { plugins: [i18n] } })
}

function stepKeys(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="cascade-step-"]').map(step => step.attributes('data-testid')!.replace('cascade-step-', ''))
}

function stepText(wrapper: VueWrapper, key: string): string {
  return wrapper.get(`[data-testid="cascade-step-${key}"]`).text().replace(/[\u00a0\u202f]/g, ' ')
}

describe('FinancialCascade', () => {
  it('lists the steps from the revenue to the profit, jobs and line rental apart, a known zero BPC cost left out', () => {
    const wrapper = mountCascade(PROFITABLE_SALE)

    expect(stepKeys(wrapper)).toEqual(['revenue', 'brokerFee', 'salesTax', 'materials', 'jobInstall', 'lineRental', 'profit'])
    expect(stepText(wrapper, 'revenue')).toContain('2.00 M')
    expect(stepText(wrapper, 'brokerFee')).toContain('-72.00 K')
    expect(stepText(wrapper, 'salesTax')).toContain('-72.00 K')
    expect(stepText(wrapper, 'materials')).toContain('-900.00 K')
    expect(stepText(wrapper, 'jobInstall')).toContain('-200.00 K')
    expect(stepText(wrapper, 'lineRental')).toContain('-72.50 K')
    expect(stepText(wrapper, 'profit')).toContain('+683.50 K')
  })

  it('draws the waterfall once every step is known', () => {
    const wrapper = mountCascade(PROFITABLE_SALE)

    expect(wrapper.find('svg[role="img"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="cascade-bar-profit"]').attributes('data-result')).toBe('profit')
  })

  it('shows a negative profit as a loss with its sign and a bar below the zero line', () => {
    const wrapper = mountCascade(LOSING_SALE)

    expect(stepKeys(wrapper)).toContain('bpc')
    expect(stepKeys(wrapper)).not.toContain('lineRental')
    expect(stepText(wrapper, 'profit')).toContain('-372.00 K')
    expect(wrapper.get('[data-testid="cascade-bar-profit"]').attributes('data-result')).toBe('loss')
    expect(wrapper.get('[data-testid="cascade-step-profit"]').classes()).toContain('text-red-400')
  })

  it('shows unknown fees and an unknown profit instead of zero, and draws no waterfall', () => {
    const wrapper = mountCascade(UNKNOWN_FEES_SALE, 'fr')

    expect(wrapper.get('[data-testid="cascade-step-brokerFee"]').find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.get('[data-testid="cascade-step-salesTax"]').find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.get('[data-testid="cascade-step-profit"]').find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(stepText(wrapper, 'materials')).toContain('-900,00 K')
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('shows an unknown material cost and an unknown profit while the other costs stay known', () => {
    const wrapper = mountCascade(UNKNOWN_MATERIAL_PRICE_SALE)

    expect(wrapper.get('[data-testid="cascade-step-materials"]').find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
    expect(stepText(wrapper, 'jobInstall')).toContain('-100.00 K')
    expect(wrapper.get('[data-testid="cascade-step-profit"]').find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('labels the steps in French', () => {
    const wrapper = mountCascade(PROFITABLE_SALE, 'fr')

    expect(wrapper.get('[data-testid="cascade-step-revenue"]').text()).toContain('Revenu')
    expect(wrapper.get('[data-testid="cascade-step-lineRental"]').text()).toContain('Location de lignes')
    expect(wrapper.get('[data-testid="cascade-step-profit"]').text()).toContain('Profit')
  })

  it('renders nothing before the distribution is loaded', () => {
    const wrapper = mountCascade(null)

    expect(wrapper.find('[data-testid^="cascade-step-"]').exists()).toBe(false)
  })
})
