import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, MemberDistribution } from '@/stores/group-industry/types'

const UNKNOWN = '—'

function memberDistribution(overrides: Partial<MemberDistribution>): MemberDistribution {
  return {
    memberId: 'member',
    characterName: null,
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

const SALE_WITH_AN_UNKNOWN_CONTRIBUTION: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 5_000,
  brokerFee: 100,
  salesTax: 150,
  netRevenue: 4_750,
  totalProjectCost: null,
  marginPercent: null,
  members: [
    memberDistribution({ memberId: 'member-ursula', characterName: 'Ursula Kane', totalCostsEngaged: 3_000, materialCosts: 3_000 }),
    memberDistribution({ memberId: 'member-valdis', characterName: 'Valdis Orn', totalCostsEngaged: 1_000, jobInstallCosts: 1_000 }),
    memberDistribution({ memberId: 'member-wenzel', characterName: 'Wenzel Roth', totalCostsEngaged: null, jobInstallCosts: null }),
  ],
}

function mountDistribution(distribution: Distribution, locale: 'en' | 'fr'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(DistributionTable, { global: { plugins: [i18n] } })
}

function figuresOfRow(row: DOMWrapper<Element>): string[] {
  return row.findAll('td').slice(1).map(cell => cell.text())
}

function memberRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr').filter(row => row.findAll('td').length === 5 && !row.classes().includes('border-t-2'))
}

describe('DistributionTable with an approved contribution without value', () => {
  it('shows an unknown share, profit part and payout for every member and an unknown cost only for the member carrying it', () => {
    const wrapper = mountDistribution(SALE_WITH_AN_UNKNOWN_CONTRIBUTION, 'en')

    expect(memberRows(wrapper).map(row => figuresOfRow(row).map(figure => figure === UNKNOWN))).toEqual([
      [false, true, true, true],
      [false, true, true, true],
      [true, true, true, true],
    ])
  })

  it('shows an unknown total cost, share, profit part and payout, never a partial sum nor NaN', () => {
    const wrapper = mountDistribution(SALE_WITH_AN_UNKNOWN_CONTRIBUTION, 'fr')

    expect(figuresOfRow(wrapper.find('tbody tr.border-t-2'))).toEqual([UNKNOWN, UNKNOWN, UNKNOWN, UNKNOWN])
    expect(wrapper.text()).not.toContain('NaN')
    expect(wrapper.find(`[title="${fr.common.unknownValue}"]`).exists()).toBe(true)
  })
})
