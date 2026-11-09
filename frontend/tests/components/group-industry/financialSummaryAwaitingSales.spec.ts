import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution } from '@/stores/group-industry/types'

const AWAITING_SALES = 'En attente des ventes'

const MEMBER_ARIA = {
  memberId: 'member-aria',
  characterName: 'Aria Solberg',
  totalCostsEngaged: 1_000_000,
  materialCosts: 1_000_000,
  jobInstallCosts: 0,
  bpcCosts: 0,
  lineRentalCosts: 0,
  courierCosts: 0,
  sharePercent: 100,
}

const NO_SALE_YET: Distribution = {
  id: 'project-hammerhead',
  totalRevenue: 0,
  brokerFee: 0,
  salesTax: 0,
  netRevenue: null,
  totalProjectCost: 1_000_000,
  marginPercent: null,
  awaitingSales: true,
  unsoldProducts: [],
  projectedMarginPercent: null,
  members: [{ ...MEMBER_ARIA, profitPart: null, payoutTotal: null }],
}

const THIRTY_OF_FIFTY_HAMMERHEAD_SOLD: Distribution = {
  id: 'project-hammerhead',
  totalRevenue: 1_200_000,
  brokerFee: 18_000,
  salesTax: 42_000,
  netRevenue: 1_140_000,
  totalProjectCost: 1_000_000,
  marginPercent: 14,
  awaitingSales: false,
  unsoldProducts: [{ typeId: 2185, typeName: 'Hammerhead II', unsoldQuantity: 20, lastUnitPrice: 40_000 }],
  projectedMarginPercent: 90,
  members: [{ ...MEMBER_ARIA, profitPart: 140_000, payoutTotal: 1_140_000 }],
}

const EVERYTHING_SOLD: Distribution = {
  ...THIRTY_OF_FIFTY_HAMMERHEAD_SOLD,
  unsoldProducts: [],
  projectedMarginPercent: null,
}

function mountFinancialSummary(distribution: Distribution): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

function normalizedText(wrapper: VueWrapper): string {
  return wrapper.text().replace(/[\u00a0\u202f]/g, ' ')
}

describe('FinancialSummary before any sale', () => {
  it('shows no sale as the revenue and awaiting sales as the net revenue, the profit and the margin', () => {
    const text = normalizedText(mountFinancialSummary(NO_SALE_YET))

    expect(text).toContain('Aucune vente')
    expect(text.split(AWAITING_SALES)).toHaveLength(4)
    expect(text).not.toContain('−100')
    expect(text).not.toContain('-100')
    expect(text).not.toContain('+0')
  })
})

describe('FinancialSummary with partial sales', () => {
  it('labels the profit as provisional', () => {
    const text = normalizedText(mountFinancialSummary(THIRTY_OF_FIFTY_HAMMERHEAD_SOLD))

    expect(text).toContain('Profit provisoire')
    expect(text).not.toContain(AWAITING_SALES)
  })

  it('keeps the plain profit label once every product is sold', () => {
    const text = normalizedText(mountFinancialSummary(EVERYTHING_SOLD))

    expect(text).toContain(fr.groupIndustry.financialSummary.profit)
    expect(text).not.toContain('Profit provisoire')
  })
})
