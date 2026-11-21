import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

const COST_COLUMN_INDEX = 7
const REMAINING_COST_HEADER = 'Remaining Est. Cost'
const TOTAL_COST_FOOTER = 'Total Est. Cost'

function material(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'material',
    typeId: 0,
    typeName: '',
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    remainingQuantity: 0,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

const SABRE_PROJECT = {
  id: 'project-sabre',
  name: 'Sabre doctrine',
  status: 'in_progress',
  shortLinkCode: 'sabre01',
  containerNames: [],
  ownerCharacterName: 'Aria Solberg',
  ownerCorporationId: 98000001,
  membersCount: 3,
  contributionsCount: 0,
  items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
  totalBomValue: 4_020_000,
  fulfillmentPercent: 50,
  approvedContributionValue: 0,
  brokerFeePercent: 3.6,
  salesTaxPercent: 3.6,
  lineRentalRatesOverride: null,
  blacklistGroupIds: [],
  blacklistTypeIds: [],
  createdAt: '2026-10-20T18:30:00+00:00',
  myRole: 'owner',
  myStatus: 'accepted',
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
} as GroupProject

const TRITANIUM_HALF_DELIVERED = material({
  id: 'bom-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 1_000_000,
  fulfilledQuantity: 500_000,
  remainingQuantity: 500_000,
  fulfillmentPercent: 50,
  estimatedPrice: 4,
  estimatedTotal: 4_000_000,
})

const PYERITE_NOT_STARTED = material({
  id: 'bom-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  requiredQuantity: 25_000,
  remainingQuantity: 25_000,
  estimatedPrice: 10,
  estimatedTotal: 250_000,
})

const MORPHITE_DELIVERED = material({
  id: 'bom-morphite',
  typeId: 11399,
  typeName: 'Morphite',
  requiredQuantity: 10,
  fulfilledQuantity: 10,
  remainingQuantity: 0,
  fulfillmentPercent: 100,
  estimatedPrice: 2_000,
  estimatedTotal: 20_000,
  isFulfilled: true,
})

const ISOGEN_WITHOUT_PRICE = material({
  id: 'bom-isogen',
  typeId: 37,
  typeName: 'Isogen',
  requiredQuantity: 100,
  fulfilledQuantity: 40,
  remainingQuantity: 60,
  fulfillmentPercent: 40,
})

function mountBomTable(materials: BomItem[]): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = SABRE_PROJECT
  projectStore.bomItems = materials
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n] } })
}

function costCell(wrapper: VueWrapper, typeName: string): string {
  const row = wrapper.findAll('tbody tr').find(candidate => candidate.text().includes(typeName))
  if (row === undefined) throw new Error(`row ${typeName} not found`)
  return row.findAll('td')[COST_COLUMN_INDEX].text()
}

describe('BomTable cost column', () => {
  it('prices the remaining quantity of a half delivered line, not the required quantity', () => {
    const wrapper = mountBomTable([TRITANIUM_HALF_DELIVERED])

    expect(costCell(wrapper, 'Tritanium')).toContain('2.0 M')
    expect(costCell(wrapper, 'Tritanium')).not.toContain('4.0 M')
  })

  it('prices a line with nothing delivered yet on its whole required quantity', () => {
    const wrapper = mountBomTable([PYERITE_NOT_STARTED])

    expect(costCell(wrapper, 'Pyerite')).toContain('250.0 K')
  })

  it('shows a line already covered as costing nothing more, not its required quantity cost', () => {
    const wrapper = mountBomTable([MORPHITE_DELIVERED])

    expect(costCell(wrapper, 'Morphite')).not.toContain('20.0 K')
  })

  it('keeps an unknown price unknown instead of pricing the remaining quantity at 0', () => {
    const wrapper = mountBomTable([ISOGEN_WITHOUT_PRICE])

    expect(costCell(wrapper, 'Isogen')).toContain('---')
  })

  it('tells the cost of the remaining quantity apart from the total cost of the project, in both languages', () => {
    const wrapper = mountBomTable([TRITANIUM_HALF_DELIVERED])

    expect(wrapper.find('thead').text()).toContain(REMAINING_COST_HEADER)
    expect(wrapper.find('div.p-5').text()).toContain(TOTAL_COST_FOOTER)
    expect(en.groupIndustry.bom).toHaveProperty('remainingEstCost', REMAINING_COST_HEADER)
    expect(fr.groupIndustry.bom).toHaveProperty('remainingEstCost')
  })
})
