import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { Distribution, MemberDistribution } from '@/stores/group-industry/types'

const UNDISTRIBUTABLE_BANNER = 'Répartition impossible : aucune contribution approuvée n\'a de coût, le revenu net ne peut pas être réparti'

function memberDistribution(overrides: Partial<MemberDistribution>): MemberDistribution {
  return {
    memberId: 'member',
    characterName: null,
    totalCostsEngaged: 0,
    materialCosts: 0,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    sharePercent: null,
    profitPart: null,
    payoutTotal: null,
    ...overrides,
  }
}

const ARIA_FREE_LINE_RENTAL = memberDistribution({ memberId: 'member-aria', characterName: 'Aria Solberg' })
const BRANN_FREE_MATERIAL = memberDistribution({ memberId: 'member-brann', characterName: 'Brann Holt' })

function undistributableSale(members: MemberDistribution[]): Distribution {
  return {
    id: 'project-sabre',
    totalRevenue: 1_000_000,
    brokerFee: 36_000,
    salesTax: 36_000,
    netRevenue: 928_000,
    totalProjectCost: 0,
    marginPercent: null,
    members,
  }
}

const SALE_WITH_A_FREE_LINE_RENTAL_NEXT_TO_A_PRICED_MATERIAL: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 2_000_000,
  brokerFee: 0,
  salesTax: 0,
  netRevenue: 2_000_000,
  totalProjectCost: 1_000_000,
  marginPercent: 100,
  members: [
    memberDistribution({ memberId: 'member-aria', characterName: 'Aria Solberg', sharePercent: 0, profitPart: 0, payoutTotal: 0 }),
    memberDistribution({
      memberId: 'member-brann',
      characterName: 'Brann Holt',
      totalCostsEngaged: 1_000_000,
      materialCosts: 1_000_000,
      sharePercent: 100,
      profitPart: 1_000_000,
      payoutTotal: 2_000_000,
    }),
  ],
}

function mountDistribution(distribution: Distribution, locale: 'en' | 'fr'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(DistributionTable, { global: { plugins: [i18n] } })
}

function normalizedText(element: VueWrapper | DOMWrapper<Element>): string {
  return element.text().replace(/[  ]/g, ' ')
}

function figuresOfRow(row: DOMWrapper<Element>): string[] {
  return row.findAll('td').slice(1).map(cell => cell.text())
}

function memberRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  return wrapper.findAll('tbody tr').filter(row => row.findAll('td').length === 5 && !row.classes().includes('border-t-2'))
}

function totalRow(wrapper: VueWrapper): DOMWrapper<Element> {
  return wrapper.find('tbody tr.border-t-2')
}

function totalPayoutOfTheRing(wrapper: VueWrapper): string {
  const label = wrapper.findAll('p').find(paragraph => paragraph.text() === 'Total Payout')
  if (label === undefined) throw new Error('ring total payout not found')
  return label.element.parentElement!.textContent!.replace('Total Payout', '').trim()
}

describe('DistributionTable of an impossible distribution', () => {
  it('explains that the net revenue cannot be split', () => {
    const wrapper = mountDistribution(undistributableSale([ARIA_FREE_LINE_RENTAL]), 'fr')

    expect(normalizedText(wrapper)).toContain(UNDISTRIBUTABLE_BANNER)
  })

  it('shows the known costs of the member and an unknown share, profit part and payout', () => {
    const wrapper = mountDistribution(undistributableSale([ARIA_FREE_LINE_RENTAL]), 'en')

    expect(memberRows(wrapper).map(figuresOfRow)).toEqual([['0', '—', '—', '—']])
  })

  it('shows unknown totals of share, profit part and payout next to the known total cost', () => {
    const wrapper = mountDistribution(undistributableSale([ARIA_FREE_LINE_RENTAL]), 'en')

    expect(figuresOfRow(totalRow(wrapper))).toEqual(['0', '—', '—', '—'])
    expect(totalPayoutOfTheRing(wrapper)).toBe('—')
  })

  it('shows no payout of 0 ISK', () => {
    const wrapper = mountDistribution(undistributableSale([ARIA_FREE_LINE_RENTAL, BRANN_FREE_MATERIAL]), 'en')

    expect(memberRows(wrapper).map(figuresOfRow)).toEqual([['0', '—', '—', '—'], ['0', '—', '—', '—']])
    expect(wrapper.text()).not.toContain('0.0%')
    expect(wrapper.text()).not.toContain('+0')
  })

  it('draws no share of the ring and lists unknown shares in the legend', () => {
    const wrapper = mountDistribution(undistributableSale([ARIA_FREE_LINE_RENTAL, BRANN_FREE_MATERIAL]), 'en')

    expect(wrapper.findAll('svg circle[stroke-dasharray]')).toHaveLength(0)
    const legendShares = wrapper.findAll('div.space-y-2 > div').map(entry => entry.findAll('span').slice(-1)[0]!.text())
    expect(legendShares).toEqual(['—', '—'])
  })

  it('shows no loss warning', () => {
    const wrapper = mountDistribution(undistributableSale([ARIA_FREE_LINE_RENTAL]), 'en')

    expect(wrapper.text()).not.toContain('Loss Scenario')
  })
})

describe('DistributionTable of a distribution with a free line rental', () => {
  it('shows the known share and payout of 0 of the free line rental', () => {
    const wrapper = mountDistribution(SALE_WITH_A_FREE_LINE_RENTAL_NEXT_TO_A_PRICED_MATERIAL, 'en')

    expect(memberRows(wrapper).map(figuresOfRow)).toEqual([
      ['0', '0.0%', '+0', '0'],
      ['1.00 M', '100.0%', '+1.00 M', '2.00 M'],
    ])
    expect(figuresOfRow(totalRow(wrapper))).toEqual(['1.00 M', '100%', '+1.00 M', '2.00 M'])
  })

  it('draws one share of the ring per member and shows no impossible distribution banner', () => {
    const wrapper = mountDistribution(SALE_WITH_A_FREE_LINE_RENTAL_NEXT_TO_A_PRICED_MATERIAL, 'fr')

    expect(wrapper.findAll('svg circle[stroke-dasharray]')).toHaveLength(2)
    expect(normalizedText(wrapper)).not.toContain('Répartition impossible')
  })
})
