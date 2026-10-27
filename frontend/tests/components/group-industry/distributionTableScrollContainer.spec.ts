import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { MemberDistribution } from '@/stores/group-industry/types'
import { tablesInScrollContainer } from '../tableScrollContainer'

const BUILDER_SHARE: MemberDistribution = {
  memberId: 'member-builder',
  characterName: 'Bob Builder',
  totalCostsEngaged: 3_000_000,
  materialCosts: 2_400_000,
  jobInstallCosts: 600_000,
  bpcCosts: 0,
  lineRentalCosts: 0,
  sharePercent: 75,
  profitPart: 300_000,
  payoutTotal: 3_300_000,
}

const MINER_SHARE: MemberDistribution = {
  memberId: 'member-miner',
  characterName: 'Alice Miner',
  totalCostsEngaged: 1_000_000,
  materialCosts: 1_000_000,
  jobInstallCosts: 0,
  bpcCosts: 0,
  lineRentalCosts: 0,
  sharePercent: 25,
  profitPart: 100_000,
  payoutTotal: 1_100_000,
}

function mountDistribution() {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = {
    id: 'distribution',
    totalRevenue: 4_400_000,
    brokerFee: 0,
    salesTax: 0,
    netRevenue: 4_400_000,
    totalProjectCost: 4_000_000,
    marginPercent: 10,
    members: [BUILDER_SHARE, MINER_SHARE],
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(DistributionTable, { global: { plugins: [i18n] } })
}

describe('DistributionTable on a narrow screen', () => {
  it('puts the member payout table in a horizontally scrollable container', () => {
    const wrapper = mountDistribution()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})
