import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

const bomLabels = en.groupIndustry.bom

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
    ...overrides,
  }
}

function sabreProject(overrides: Partial<GroupProject>): GroupProject {
  return {
    id: 'project-sabre',
    name: 'Sabre doctrine',
    status: 'in_progress',
    shortLinkCode: 'sabre01',
    containerName: null,
    ownerCharacterName: 'Aria Solberg',
    ownerCorporationId: 98000001,
    membersCount: 3,
    items: [{ typeId: 22456, typeName: 'Sabre', meLevel: 10, teLevel: 20, runs: 10 }],
    totalBomValue: 0,
    fulfillmentPercent: 0,
    approvedContributionValue: 0,
    brokerFeePercent: 3.6,
    salesTaxPercent: 3.6,
    lineRentalRatesOverride: null,
    blacklistGroupIds: [],
    blacklistTypeIds: [],
    createdAt: '2026-10-20T18:30:00+00:00',
    myRole: 'owner',
    myStatus: 'accepted',
    ...overrides,
  }
}

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

function mountBomTable(project: GroupProject, materials: BomItem[]): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = project
  projectStore.bomItems = materials
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n] } })
}

function footerFigure(wrapper: VueWrapper, label: string): DOMWrapper<Element> {
  const figure = wrapper.find('div.p-5').findAll('div').find(candidate => candidate.find('p').exists() && candidate.find('p').text() === label)
  if (figure === undefined) throw new Error(`footer figure ${label} not found`)
  return figure
}

function valueOf(figure: DOMWrapper<Element>, label: string): string {
  return figure.text().slice(label.length).trim()
}

describe('BomTable footer', () => {
  it('shows the unknown BOM value of the project, not the sum of the priced lines', () => {
    const wrapper = mountBomTable(
      sabreProject({ totalBomValue: null, fulfillmentPercent: 25 }),
      [
        material({ id: 'bom-mexallon', typeId: 36, typeName: 'Mexallon', requiredQuantity: 300, estimatedPrice: 50, estimatedTotal: 15_000 }),
        material({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen', requiredQuantity: 100, estimatedPrice: null, estimatedTotal: null }),
      ],
    )

    const totalEstCost = footerFigure(wrapper, bomLabels.totalEstCost)
    expect(valueOf(totalEstCost, bomLabels.totalEstCost)).toBe('—')
    expect(totalEstCost.find(`[title="${en.common.unknownValue}"]`).exists()).toBe(true)
  })

  it('shows the BOM value of the project instead of adding up the lines', () => {
    const wrapper = mountBomTable(
      sabreProject({ totalBomValue: 840, fulfillmentPercent: 0 }),
      [
        material({ id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', requiredQuantity: 25, estimatedPrice: 10, estimatedTotal: 250 }),
        material({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen', requiredQuantity: 7, estimatedPrice: 50, estimatedTotal: 350 }),
      ],
    )

    expect(valueOf(footerFigure(wrapper, bomLabels.totalEstCost), bomLabels.totalEstCost)).toBe('840')
  })

  it('shows the BOM progress of the project, not the share of delivered units', () => {
    const wrapper = mountBomTable(
      sabreProject({ totalBomValue: 4_020_000, fulfillmentPercent: 75 }),
      [TRITANIUM_HALF_DELIVERED, MORPHITE_DELIVERED],
    )

    const overallFulfillment = footerFigure(wrapper, bomLabels.overallFulfillment)
    expect(overallFulfillment.text()).toContain('75%')
    expect(overallFulfillment.text()).not.toContain('50%')
  })

  it('rounds the BOM progress of the project to a whole percent', () => {
    const wrapper = mountBomTable(
      sabreProject({ totalBomValue: null, fulfillmentPercent: 16.67 }),
      [
        material({ id: 'bom-mexallon', typeId: 36, typeName: 'Mexallon', requiredQuantity: 300_000, fulfilledQuantity: 100_000, remainingQuantity: 200_000, fulfillmentPercent: 33.33, estimatedPrice: 50, estimatedTotal: 15_000_000 }),
        material({ id: 'bom-isogen', typeId: 37, typeName: 'Isogen', requiredQuantity: 100_000, remainingQuantity: 100_000 }),
      ],
    )

    const overallFulfillment = footerFigure(wrapper, bomLabels.overallFulfillment)
    expect(overallFulfillment.text()).toContain('17%')
    expect(overallFulfillment.text()).not.toContain('25%')
    expect(overallFulfillment.text()).not.toContain('16.67')
  })
})
