import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution } from '@/stores/group-industry/types'
import { profitableDistribution } from './projectDiscordRecapFixtures'

const CONTRACT_FEES_ROW = '[data-testid="contract-fees-row"]'
const REVENUE_SPLIT = '[data-testid="revenue-split"]'

function mountWith(distribution: Distribution): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

describe('FinancialSummary contract sales', () => {
  it('shows neither the contract fees nor the revenue split for a project sold on the market only', () => {
    const wrapper = mountWith(profitableDistribution({ marketRevenue: 2_000_000, contractRevenue: 0, contractFees: 0 }))

    expect(wrapper.find(CONTRACT_FEES_ROW).exists()).toBe(false)
    expect(wrapper.find(REVENUE_SPLIT).exists()).toBe(false)
  })

  it('shows the revenue of the market and of the contracts apart', () => {
    const wrapper = mountWith(profitableDistribution({ marketRevenue: 1_500_000, contractRevenue: 500_000, contractFees: 40_000 }))

    const split = wrapper.get(REVENUE_SPLIT).text()
    expect(split).toContain('1.50 M')
    expect(split).toContain('500.00 K')
  })

  it('deducts the known fees of the contracts from the revenue', () => {
    const wrapper = mountWith(profitableDistribution({ marketRevenue: 1_500_000, contractRevenue: 500_000, contractFees: 40_000 }))

    expect(wrapper.get(CONTRACT_FEES_ROW).text()).toContain('-40.00 K')
  })

  it('says the fees are unknown, never zero, while the journal has no line for a contract', () => {
    const wrapper = mountWith(profitableDistribution({ marketRevenue: 0, contractRevenue: 500_000, contractFees: null, netRevenue: null }))

    expect(wrapper.get(CONTRACT_FEES_ROW).find('[data-testid="contract-fees-unknown"]').exists()).toBe(true)
  })

  it('treats a distribution without the field as unknown fees of a contract revenue', () => {
    const wrapper = mountWith(profitableDistribution({ marketRevenue: 0, contractRevenue: 500_000, netRevenue: null }))

    expect(wrapper.get(CONTRACT_FEES_ROW).find('[data-testid="contract-fees-unknown"]').exists()).toBe(true)
  })
})
