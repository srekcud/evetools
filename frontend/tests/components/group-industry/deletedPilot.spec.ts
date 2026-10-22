import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import DistributionTable from '@/components/group-industry/DistributionTable.vue'
import { useEveImages } from '@/composables/useEveImages'
import { useGroupDistributionStore } from '@/stores/group-industry/distribution'
import type { MemberDistribution } from '@/stores/group-industry/types'

function memberDistribution(overrides: Partial<MemberDistribution>): MemberDistribution {
  return {
    memberId: 'member',
    characterName: null,
    totalCostsEngaged: 0,
    materialCosts: 0,
    jobInstallCosts: 0,
    bpcCosts: 0,
    lineRentalCosts: 0,
    sharePercent: 0,
    profitPart: 0,
    payoutTotal: 0,
    ...overrides,
  }
}

const DELETED_MEMBER = memberDistribution({
  memberId: 'member-deleted',
  characterName: null,
  totalCostsEngaged: 1_000_000,
  materialCosts: 1_000_000,
  sharePercent: 25,
  profitPart: 100_000,
  payoutTotal: 1_100_000,
})

const ACTIVE_MEMBER = memberDistribution({
  memberId: 'member-active',
  characterName: 'Bob Builder',
  totalCostsEngaged: 3_000_000,
  materialCosts: 3_000_000,
  sharePercent: 75,
  profitPart: 300_000,
  payoutTotal: 3_300_000,
})

function mountDistribution(locale: 'en' | 'fr'): VueWrapper {
  setActivePinia(createPinia())
  useGroupDistributionStore().distribution = {
    id: 'distribution',
    totalRevenue: 4_400_000,
    brokerFee: 0,
    salesTax: 0,
    netRevenue: 4_400_000,
    totalProjectCost: 4_000_000,
    marginPercent: 10,
    members: [DELETED_MEMBER, ACTIVE_MEMBER],
  }
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(DistributionTable, { global: { plugins: [i18n] } })
}

describe('DistributionTable with a deleted account', () => {
  it('shows the deleted member as "Deleted pilot" next to the other members', () => {
    const wrapper = mountDistribution('en')

    const rowNames = wrapper.findAll('tbody tr td:first-child span.font-medium').map(span => span.text())
    expect(rowNames).toEqual([en.common.deletedPilot, 'Bob Builder'])
    expect(wrapper.text()).toContain('Deleted pilot')
  })

  it('shows the deleted member as "Pilote supprimé" in French', () => {
    const wrapper = mountDistribution('fr')

    expect(wrapper.text()).toContain('Pilote supprimé')
    expect(wrapper.text()).not.toContain('null')
  })
})

describe('character portrait of a deleted account', () => {
  it('does not request an EVE image when the character id is null', () => {
    const { getCharacterPortraitUrl, getFallbackIconUrl } = useEveImages()

    expect(getCharacterPortraitUrl(null, 32)).toBe(getFallbackIconUrl())
  })
})
