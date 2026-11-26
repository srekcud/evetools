import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution } from '@/stores/group-industry/types'
import { profitableDistribution } from './projectDiscordRecapFixtures'

const UNMATCHED_SALES = '[data-testid="unmatched-sales"]'

function mountWith(distribution: Distribution): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

describe('FinancialSummary sales outside the final products', () => {
  it('shows nothing when every recorded sale is a final product', () => {
    const wrapper = mountWith(profitableDistribution({ unmatchedSales: [] }))

    expect(wrapper.find(UNMATCHED_SALES).exists()).toBe(false)
  })

  it('shows nothing for a distribution without the field', () => {
    const wrapper = mountWith(profitableDistribution())

    expect(wrapper.find(UNMATCHED_SALES).exists()).toBe(false)
  })

  it('names each type sold outside the final products with its quantity and revenue', () => {
    const wrapper = mountWith(profitableDistribution({
      unmatchedSales: [
        { typeId: 638, typeName: 'Raven', quantity: 3, revenue: 33_300_000 },
        { typeId: 22456, typeName: 'Sabre', quantity: 10, revenue: 1_000_000 },
      ],
    }))

    const notice = wrapper.get(UNMATCHED_SALES).text()
    expect(notice).toContain('Raven')
    expect(notice).toContain('3')
    expect(notice).toContain('33.30 M')
    expect(notice).toContain('Sabre')
    expect(notice).toContain('1.00 M')
  })
})
