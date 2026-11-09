import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, MemberDistribution } from '@/stores/group-industry/types'

const AWAITING_SALES = 'En attente des ventes'
const AWAITING_SALES_BANNER = 'Aucune vente enregistrée : le revenu, le profit, la marge et les payouts seront connus à la première vente.'
const UNDISTRIBUTABLE_BANNER = 'Répartition impossible'

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

const ARIA_BEFORE_ANY_SALE = memberDistribution({
  memberId: 'member-aria',
  characterName: 'Aria Solberg',
  totalCostsEngaged: 600_000,
  materialCosts: 600_000,
  sharePercent: 60,
})
const BRANN_BEFORE_ANY_SALE = memberDistribution({
  memberId: 'member-brann',
  characterName: 'Brann Holt',
  totalCostsEngaged: 400_000,
  materialCosts: 400_000,
  sharePercent: 40,
})
const ARIA_WITH_PARTIAL_SALES = memberDistribution({
  memberId: 'member-aria',
  characterName: 'Aria Solberg',
  totalCostsEngaged: 600_000,
  materialCosts: 600_000,
  sharePercent: 60,
  profitPart: 84_000,
  payoutTotal: 684_000,
})
const BRANN_WITH_PARTIAL_SALES = memberDistribution({
  memberId: 'member-brann',
  characterName: 'Brann Holt',
  totalCostsEngaged: 400_000,
  materialCosts: 400_000,
  sharePercent: 40,
  profitPart: 56_000,
  payoutTotal: 456_000,
})

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
  members: [ARIA_BEFORE_ANY_SALE, BRANN_BEFORE_ANY_SALE],
}

const NO_SALE_YET_WITH_ZERO_COST: Distribution = {
  ...NO_SALE_YET,
  totalProjectCost: 0,
  members: [memberDistribution({ memberId: 'member-aria', characterName: 'Aria Solberg' })],
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
  members: [ARIA_WITH_PARTIAL_SALES, BRANN_WITH_PARTIAL_SALES],
}

const PARTIAL_SALES_AT_A_LOSS: Distribution = {
  ...THIRTY_OF_FIFTY_HAMMERHEAD_SOLD,
  totalRevenue: 900_000,
  brokerFee: 13_500,
  salesTax: 31_500,
  netRevenue: 855_000,
  marginPercent: -14.5,
  unsoldProducts: [{ typeId: 2185, typeName: 'Hammerhead II', unsoldQuantity: 20, lastUnitPrice: 30_000 }],
  projectedMarginPercent: 42.5,
}

const HAMMERHEAD_SOLD_BUT_WARRIOR_NEVER_SOLD: Distribution = {
  ...THIRTY_OF_FIFTY_HAMMERHEAD_SOLD,
  totalRevenue: 1_000_000,
  brokerFee: 15_000,
  salesTax: 35_000,
  netRevenue: 950_000,
  marginPercent: -5,
  unsoldProducts: [
    { typeId: 2185, typeName: 'Hammerhead II', unsoldQuantity: 25, lastUnitPrice: 40_000 },
    { typeId: 2486, typeName: 'Warrior II', unsoldQuantity: 30, lastUnitPrice: null },
  ],
  projectedMarginPercent: null,
}

const EVERYTHING_SOLD_AT_A_LOSS: Distribution = {
  ...THIRTY_OF_FIFTY_HAMMERHEAD_SOLD,
  totalRevenue: 800_000,
  brokerFee: 12_000,
  salesTax: 28_000,
  netRevenue: 760_000,
  marginPercent: -24,
  unsoldProducts: [],
  projectedMarginPercent: null,
  members: [
    { ...ARIA_WITH_PARTIAL_SALES, profitPart: -144_000, payoutTotal: 456_000 },
    { ...BRANN_WITH_PARTIAL_SALES, profitPart: -96_000, payoutTotal: 304_000 },
  ],
}

function mountDistribution(distribution: Distribution): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })
  return mount(DistributionTable, { global: { plugins: [i18n] } })
}

function normalizedText(element: VueWrapper | DOMWrapper<Element>): string {
  return element.text().replace(/[\u00a0\u202f]/g, ' ')
}

function memberRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr').filter(row => row.findAll('td').length === 5 && !row.classes().includes('border-t-2'))
}

function payoutCellOf(row: DOMWrapper<Element>): string {
  return normalizedText(row.findAll('td')[4]!)
}

describe('DistributionTable before any sale', () => {
  it('announces that the figures will be known at the first sale, without a loss or a total loss margin', () => {
    const wrapper = mountDistribution(NO_SALE_YET)

    const banner = wrapper.find('[data-testid="awaiting-sales-banner"]')
    expect(banner.exists()).toBe(true)
    expect(normalizedText(banner)).toContain(AWAITING_SALES)
    expect(normalizedText(banner)).toContain(AWAITING_SALES_BANNER)
    expect(wrapper.find('[data-testid="loss-scenario"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="provisional-margin-banner"]').exists()).toBe(false)
    expect(normalizedText(wrapper)).not.toContain('−100')
    expect(normalizedText(wrapper)).not.toContain('-100')
  })

  it('shows awaiting sales instead of the payout of every member and of the total', () => {
    const wrapper = mountDistribution(NO_SALE_YET)

    expect(memberRows(wrapper).map(payoutCellOf)).toEqual([AWAITING_SALES, AWAITING_SALES])
    const totalPayout = wrapper.find('[data-testid="total-payout"]')
    expect(totalPayout.exists()).toBe(true)
    expect(normalizedText(totalPayout)).toBe(AWAITING_SALES)
  })

  it('shows awaiting sales rather than an impossible distribution when the total project cost is zero', () => {
    const wrapper = mountDistribution(NO_SALE_YET_WITH_ZERO_COST)

    expect(wrapper.find('[data-testid="awaiting-sales-banner"]').exists()).toBe(true)
    expect(normalizedText(wrapper)).not.toContain(UNDISTRIBUTABLE_BANNER)
  })
})

describe('DistributionTable with partial sales', () => {
  it('lists the unsold units with their last sale price and the projected margin', () => {
    const wrapper = mountDistribution(THIRTY_OF_FIFTY_HAMMERHEAD_SOLD)

    const banner = wrapper.find('[data-testid="provisional-margin-banner"]')
    expect(banner.exists()).toBe(true)
    expect(normalizedText(banner)).toContain('Marge provisoire')
    expect(normalizedText(banner)).toContain('Invendus :')
    const unsoldLines = banner.findAll('[data-testid="unsold-product"]').map(normalizedText)
    expect(unsoldLines).toHaveLength(1)
    expect(unsoldLines[0]).toContain('Hammerhead II')
    expect(unsoldLines[0]).toContain('20')
    expect(unsoldLines[0]).toContain('40,00 K')
    const projectedMargin = banner.find('[data-testid="projected-margin"]')
    expect(normalizedText(projectedMargin)).toContain('Marge projetée au dernier prix de vente')
    expect(normalizedText(projectedMargin)).toContain('90.0%')
    expect(wrapper.find('[data-testid="awaiting-sales-banner"]').exists()).toBe(false)
  })

  it('keeps the payouts of the realised revenue and shows no loss scenario while products remain unsold', () => {
    const wrapper = mountDistribution(PARTIAL_SALES_AT_A_LOSS)

    expect(wrapper.find('[data-testid="provisional-margin-banner"]').exists()).toBe(true)
    expect(normalizedText(wrapper.find('[data-testid="projected-margin"]'))).toContain('42.5%')
    expect(wrapper.find('[data-testid="loss-scenario"]').exists()).toBe(false)
    expect(memberRows(wrapper).map(payoutCellOf)).not.toContain(AWAITING_SALES)
  })

  it('shows a never sold product as never sold and the projected margin as unknown', () => {
    const wrapper = mountDistribution(HAMMERHEAD_SOLD_BUT_WARRIOR_NEVER_SOLD)

    const unsoldLines = wrapper.findAll('[data-testid="unsold-product"]').map(normalizedText)
    expect(unsoldLines).toHaveLength(2)
    expect(unsoldLines[0]).toContain('Hammerhead II')
    expect(unsoldLines[0]).toContain('25')
    expect(unsoldLines[0]).not.toContain('jamais vendu')
    expect(unsoldLines[1]).toContain('Warrior II')
    expect(unsoldLines[1]).toContain('30')
    expect(unsoldLines[1]).toContain('jamais vendu')
    const projectedMargin = wrapper.find('[data-testid="projected-margin"]')
    expect(projectedMargin.exists()).toBe(true)
    expect(normalizedText(projectedMargin)).toContain('—')
    expect(normalizedText(projectedMargin)).not.toContain('%')
  })
})

describe('DistributionTable with every product sold', () => {
  it('still shows the loss scenario of a negative margin, without provisional or awaiting banners', () => {
    const wrapper = mountDistribution(EVERYTHING_SOLD_AT_A_LOSS)

    expect(wrapper.find('[data-testid="loss-scenario"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="provisional-margin-banner"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="awaiting-sales-banner"]').exists()).toBe(false)
  })
})
