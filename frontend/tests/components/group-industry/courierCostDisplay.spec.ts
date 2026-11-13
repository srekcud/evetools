import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, MemberDistribution } from '@/stores/group-industry/types'
import { present } from '../../present'

const UNKNOWN = '—'
const COURIER_REWARD = 376_550

function memberDistribution(overrides: Partial<MemberDistribution>): MemberDistribution {
  return {
    memberId: 'member-aria',
    characterName: 'Aria Solberg',
    totalCostsEngaged: 0,
    materialCosts: 0,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    courierCosts: 0,
    sharePercent: null,
    profitPart: null,
    payoutTotal: null,
    ...overrides,
  }
}

function distributionOf(owner: Partial<MemberDistribution>, totalProjectCost: number | null): Distribution {
  return {
    id: 'project-ishtar',
    totalRevenue: 2_000_000,
    brokerFee: 72_000,
    salesTax: 72_000,
    netRevenue: 1_856_000,
    totalProjectCost,
    marginPercent: null,
    awaitingSales: false,
    unsoldProducts: [],
    projectedMarginPercent: null,
    members: [memberDistribution(owner)],
  }
}

const WITH_DELIVERED_COURIER = distributionOf({ totalCostsEngaged: 876_550, materialCosts: 500_000, courierCosts: COURIER_REWARD }, 876_550)
const WITHOUT_COURIER = distributionOf({ totalCostsEngaged: 500_000, materialCosts: 500_000 }, 500_000)
const WITH_UNKNOWN_COURIER = distributionOf({ totalCostsEngaged: null, materialCosts: 500_000, courierCosts: null }, null)

function mountWith(component: object, distribution: Distribution, locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(component, { global: { plugins: [i18n] } })
}

function cascadeStepKeys(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="cascade-step-"]').map(step => present(step.attributes('data-testid')).replace('cascade-step-', ''))
}

async function expandedBreakdown(distribution: Distribution): Promise<string> {
  const wrapper = mountWith(DistributionTable, distribution)
  await wrapper.get('tbody tr.cursor-pointer').trigger('click')
  return wrapper.text().replace(/[\u00a0\u202f]/g, ' ')
}

describe('courier reward in the distribution displays', () => {
  it('lists a courier step after the line rental in the cascade with the reward', () => {
    const wrapper = mountWith(FinancialCascade, WITH_DELIVERED_COURIER)

    expect(cascadeStepKeys(wrapper)).toEqual(['revenue', 'brokerFee', 'salesTax', 'materials', 'jobInstall', 'courier', 'profit'])
    expect(wrapper.get('[data-testid="cascade-step-courier"]').text().replace(/[\u00a0\u202f]/g, ' ')).toContain('-376.55 K')
    expect(wrapper.get('[data-testid="cascade-step-courier"]').text()).toContain(en.groupIndustry.financialCascade.courier)
  })

  it('leaves the courier step out of the cascade when nothing is counted', () => {
    expect(cascadeStepKeys(mountWith(FinancialCascade, WITHOUT_COURIER))).not.toContain('courier')
  })

  it('shows an unknown courier step instead of zero when the reward is unknown', () => {
    const wrapper = mountWith(FinancialCascade, WITH_UNKNOWN_COURIER, 'fr')

    expect(wrapper.get('[data-testid="cascade-step-courier"]').find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
    expect(wrapper.text()).not.toContain('NaN')
  })

  it('shows a courier line in the financial summary only when the reward is counted', () => {
    const counted = mountWith(FinancialSummary, WITH_DELIVERED_COURIER)
    const notCounted = mountWith(FinancialSummary, WITHOUT_COURIER)

    expect(counted.text()).toContain(en.groupIndustry.financialSummary.courier)
    expect(counted.text().replace(/[\u00a0\u202f]/g, ' ')).toContain('376.55 K')
    expect(notCounted.text()).not.toContain(en.groupIndustry.financialSummary.courier)
  })

  it('shows the courier cost in the breakdown of the member carrying it', async () => {
    const breakdown = await expandedBreakdown(WITH_DELIVERED_COURIER)

    expect(breakdown).toContain(en.groupIndustry.distribution.courierCosts)
    expect(breakdown).toContain('376.55 K')
  })

  it('hides the courier line of the breakdown when nothing is counted and shows an unknown value when the reward is unknown', async () => {
    expect(await expandedBreakdown(WITHOUT_COURIER)).not.toContain(en.groupIndustry.distribution.courierCosts)
    const unknownBreakdown = await expandedBreakdown(WITH_UNKNOWN_COURIER)
    expect(unknownBreakdown).toContain(en.groupIndustry.distribution.courierCosts)
    expect(unknownBreakdown).toContain(UNKNOWN)
  })
})
