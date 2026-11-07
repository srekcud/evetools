import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FinancialSummary from '@/components/group-industry/FinancialSummary.vue'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { Distribution, GroupProject } from '@/stores/group-industry/types'
import { expectedMessage } from '../../expectedMessage'
import type { TestLocale } from '../../localeTexts'

const UNKNOWN_FEES_KEY = 'groupIndustry.financialSummary.feesUnknown'

const PROJECT_WITHOUT_SELLER_FEES: GroupProject = {
  id: 'project-sabre',
  name: 'Sabre batch',
  status: 'selling',
  shortLinkCode: 'sabre242',
  containerName: null,
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: null,
  membersCount: 1,
  items: [],
  totalBomValue: null,
  fulfillmentPercent: 100,
  approvedContributionValue: 0,
  brokerFeePercent: null,
  salesTaxPercent: null,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-19T12:00:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  minimumOutsideSaving: 5,
}

const DISTRIBUTION_WITH_UNKNOWN_FEES: Distribution = {
  id: 'project-sabre',
  totalRevenue: 1_000_000,
  brokerFee: null,
  salesTax: null,
  netRevenue: null,
  totalProjectCost: 600_000,
  marginPercent: null,
  members: [{
    memberId: 'member-aria',
    characterName: 'Aria Solberg',
    totalCostsEngaged: 600_000,
    materialCosts: 600_000,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    courierCosts: 0,
    sharePercent: 100,
    profitPart: null,
    payoutTotal: null,
  }],
}

function mountSummary(locale: TestLocale): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().currentProject = PROJECT_WITHOUT_SELLER_FEES
  useGroupDistributionStore().distribution = DISTRIBUTION_WITH_UNKNOWN_FEES
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(FinancialSummary, { global: { plugins: [i18n] } })
}

describe('FinancialSummary with unknown seller fees', () => {
  it('shows the fees, the net revenue, the profit and the margin as unknown with the reason, and keeps the known amounts', () => {
    const wrapper = mountSummary('en')

    const text = wrapper.text()
    expect({
      reason: text.includes(expectedMessage('en', UNKNOWN_FEES_KEY)),
      unknownValues: wrapper.findAll(`[title="${en.common.unknownValue}"]`).length >= 5,
      knownAmounts: [text.includes('1.00 M'), text.includes('600.00 K')],
      garbage: /NaN|null|undefined|---|-0\.00|\+0\.00/.test(text),
      percentOfNothing: /\(\s*%\)|0\.0%/.test(text),
    }).toEqual({ reason: true, unknownValues: true, knownAmounts: [true, true], garbage: false, percentOfNothing: false })
  })

  it('shows the reason in French', () => {
    const wrapper = mountSummary('fr')

    expect(wrapper.text()).toContain(expectedMessage('fr', UNKNOWN_FEES_KEY))
    expect(expectedMessage('fr', UNKNOWN_FEES_KEY)).not.toBe(expectedMessage('en', UNKNOWN_FEES_KEY))
  })
})
