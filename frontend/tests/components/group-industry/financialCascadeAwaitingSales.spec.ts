import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution } from '@/stores/group-industry/types'

const NO_SALE_YET: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 0,
  brokerFee: null,
  salesTax: null,
  netRevenue: null,
  totalProjectCost: 1_172_498.42,
  marginPercent: null,
  awaitingSales: true,
  unsoldProducts: [],
  projectedMarginPercent: null,
  members: [],
}

const FEES_MISSING_AFTER_A_SALE: Distribution = {
  ...NO_SALE_YET,
  totalRevenue: 2_000_000,
  awaitingSales: false,
}

function mountCascade(distribution: Distribution): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  return mount(FinancialCascade, { global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] } })
}

describe('cascade while the sales are missing', () => {
  it('says the project awaits its sales instead of asking for every fee and cost', () => {
    const wrapper = mountCascade(NO_SALE_YET)

    expect(wrapper.get('[data-testid="cascade-awaiting-sales"]').text()).toContain(en.groupIndustry.distribution.awaitingSales)
    expect(wrapper.text()).not.toContain(en.groupIndustry.financialCascade.unavailable)
  })

  it('keeps the missing fee message once a sale is recorded', () => {
    const wrapper = mountCascade(FEES_MISSING_AFTER_A_SALE)

    expect(wrapper.find('[data-testid="cascade-awaiting-sales"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(en.groupIndustry.financialCascade.unavailable)
  })
})
