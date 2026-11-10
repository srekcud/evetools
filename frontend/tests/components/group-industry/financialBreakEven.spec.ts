import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, GroupSale } from '@/stores/group-industry/types'

const BASE_DISTRIBUTION: Distribution = {
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
  breakEvenPerUnit: 96_666.67,
  breakEvenUnknownReason: null,
  members: [],
}

function sale(quantity: number, unitPrice: number): GroupSale {
  return {
    id: `sale-${quantity}-${unitPrice}`,
    typeId: 11993,
    typeName: 'Hammerhead II',
    quantity,
    unitPrice,
    totalPrice: quantity * unitPrice,
    venue: null,
    channel: 'market',
    soldAt: '2026-11-05T20:41:00+00:00',
    recordedByCharacterName: null,
    sellerCharacterId: null,
    sellerCharacterName: null,
    linkedToWalletTransaction: false,
  }
}

function mountCascade(distribution: Distribution, sales: GroupSale[] = [], locale: 'en' | 'fr' = 'en'): VueWrapper {
  setActivePinia(createPinia())
  const store = useGroupDistributionStore()
  store.distribution = distribution
  store.sales = sales
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(FinancialCascade, { global: { plugins: [i18n] } })
}

function normalized(wrapper: VueWrapper, testId: string): string {
  return wrapper.get(`[data-testid="${testId}"]`).text().replace(/[\u00a0\u202f]/g, ' ')
}

describe('FinancialCascade break-even', () => {
  it('shows the break-even price per unit', () => {
    const wrapper = mountCascade(BASE_DISTRIBUTION)

    expect(normalized(wrapper, 'break-even-value')).toContain('96.67 K')
    expect(wrapper.find('[data-testid="break-even-unknown"]').exists()).toBe(false)
  })

  it('compares the average price obtained with the break-even on a gauge, above it when profitable', () => {
    const wrapper = mountCascade(BASE_DISTRIBUTION, [sale(30, 120_000), sale(20, 90_000)])

    expect(normalized(wrapper, 'break-even-average-price')).toContain('108.00 K')
    expect(wrapper.get('[data-testid="break-even-gauge"]').attributes('data-average-price-side')).toBe('above')
  })

  it('puts the average price below the break-even when each sale loses money', () => {
    const wrapper = mountCascade(BASE_DISTRIBUTION, [sale(50, 80_000)])

    expect(wrapper.get('[data-testid="break-even-gauge"]').attributes('data-average-price-side')).toBe('below')
  })

  it('shows the break-even alone while nothing is sold', () => {
    const wrapper = mountCascade(BASE_DISTRIBUTION)

    expect(wrapper.find('[data-testid="break-even-average-price"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="break-even-gauge"]').attributes('data-average-price-side')).toBe('none')
  })

  it('explains an unknown break-even by its cause instead of showing a value', () => {
    const wrapper = mountCascade({ ...BASE_DISTRIBUTION, breakEvenPerUnit: null, breakEvenUnknownReason: 'jobs_not_delivered' })

    expect(wrapper.get('[data-testid="break-even-unknown"]').text()).toContain('Final jobs are not all delivered yet')
    expect(wrapper.find('[data-testid="break-even-value"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="break-even-gauge"]').exists()).toBe(false)
  })

  it('labels the break-even in French', () => {
    const wrapper = mountCascade(BASE_DISTRIBUTION, [], 'fr')

    expect(wrapper.get('[data-testid="break-even"]').text()).toContain('Seuil de rentabilité')
  })
})
