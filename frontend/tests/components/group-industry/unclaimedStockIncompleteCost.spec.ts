import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import FinancialCascade from '@/components/group-industry/FinancialCascade.vue'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import ProjectTransfers from '@/components/group-industry/ProjectTransfers.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupSettlementStore } from '@/stores/group-industry/settlement'
import type { Distribution } from '@/stores/group-industry/types'
import { projectSettlement } from './projectSettlementFixtures'

enableAutoUnmount(afterEach)

const UNCLAIMED_LINES = [
  { bomLineId: 'line-tritanium', typeId: 34, typeName: 'Tritanium', quantity: 50 },
  { bomLineId: 'line-pyerite', typeId: 35, typeName: 'Pyerite', quantity: 12 },
]

const DISTRIBUTION_WITH_UNCLAIMED_STOCK: Distribution = {
  id: 'project-ishtar',
  totalRevenue: 5_000,
  brokerFee: 100,
  salesTax: 150,
  netRevenue: 4_750,
  totalProjectCost: null,
  marginPercent: null,
  awaitingSales: false,
  unsoldProducts: [],
  projectedMarginPercent: null,
  breakEvenPerUnit: null,
  breakEvenUnknownReason: 'unclaimed_stock',
  costPerUnit: null,
  costPerUnitUnknownReason: 'unclaimed_stock',
  unclaimedStockLines: UNCLAIMED_LINES,
  members: [{
    memberId: 'member-ursula',
    characterName: 'Ursula Kane',
    totalCostsEngaged: 3_000,
    materialCosts: 3_000,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    courierCosts: 0,
    sharePercent: null,
    profitPart: null,
    payoutTotal: null,
  }],
}

function mountWith(component: typeof DistributionTable | typeof FinancialSummary | typeof FinancialCascade, distribution: Distribution, locale: 'en' | 'fr'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = distribution
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(component, { global: { plugins: [i18n] } })
}

describe.each([
  ['DistributionTable', DistributionTable],
  ['FinancialSummary', FinancialSummary],
] as const)('%s while container stock is unclaimed', (_name, component) => {
  it('names every line still holding unclaimed stock instead of blaming a contribution without price', () => {
    const wrapper = mountWith(component, DISTRIBUTION_WITH_UNCLAIMED_STOCK, 'en')

    const notice = wrapper.get('[data-testid="unclaimed-stock-notice"]').text()
    expect(notice).toContain('Tritanium')
    expect(notice).toContain('50')
    expect(notice).toContain('Pyerite')
    expect(notice).toContain('12')
    expect(wrapper.text()).not.toContain(en.groupIndustry.distribution.unknownContributionValue)
  })

  it('words the notice in French too', () => {
    const wrapper = mountWith(component, DISTRIBUTION_WITH_UNCLAIMED_STOCK, 'fr')

    expect(wrapper.get('[data-testid="unclaimed-stock-notice"]').text()).toContain('Tritanium')
    expect(wrapper.text()).not.toContain(fr.groupIndustry.distribution.unknownContributionValue)
  })

  it('keeps the plain unknown-price message when no line holds unclaimed stock', () => {
    const wrapper = mountWith(component, { ...DISTRIBUTION_WITH_UNCLAIMED_STOCK, unclaimedStockLines: [], breakEvenUnknownReason: 'cost_unknown' }, 'en')

    expect(wrapper.find('[data-testid="unclaimed-stock-notice"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(en.groupIndustry.distribution.unknownContributionValue)
  })
})

describe('FinancialCascade while container stock is unclaimed', () => {
  it('explains the unknown break-even and cost per unit by the unclaimed stock', () => {
    const wrapper = mountWith(FinancialCascade, DISTRIBUTION_WITH_UNCLAIMED_STOCK, 'en')

    expect(wrapper.get('[data-testid="break-even-unknown"]').text()).toContain(en.groupIndustry.financialCascade.breakEven.unknown.unclaimed_stock)
  })
})

describe('ProjectTransfers while container stock is unclaimed', () => {
  it('says the transfers wait for the unclaimed stock to be claimed, assigned or donated', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useGroupSettlementStore().settlement = projectSettlement({ transfers: null, transfersUnavailableReason: 'unclaimed_stock' })
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

    const wrapper = mount(ProjectTransfers, { props: { projectId: 'project-ishtar' }, global: { plugins: [pinia, i18n] } })

    const unavailable = wrapper.get('[data-testid="transfers-unavailable"]')
    expect(unavailable.attributes('data-reason')).toBe('unclaimed_stock')
    expect(unavailable.text()).toBe(en.groupIndustry.transfers.unavailable.unclaimed_stock)
  })
})
