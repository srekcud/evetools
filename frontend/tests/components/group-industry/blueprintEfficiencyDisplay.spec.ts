import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import JobsTable from '@/components/group-industry/JobsTable.vue'
import ProductionChainJobTree from '@/components/group-industry/ProductionChainJobTree.vue'
import { showsBlueprintEfficiency } from '@/components/group-industry/blueprintEfficiency'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, ChainActivity } from '@/stores/group-industry/types'
import { chainNode } from '../../productionChainNodes'

function i18n() {
  return createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
}

function jobLine(activityType: string): BomItem {
  return {
    id: `job-${activityType}`,
    typeId: 1,
    typeName: `Job ${activityType}`,
    requiredQuantity: 1,
    fulfilledQuantity: 0,
    remainingQuantity: 1,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'final',
    activityType,
    parentTypeId: null,
    meLevel: 7,
    teLevel: 13,
    runs: 1,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
  }
}

function jobsTableShowsMeTe(activityType: string): boolean {
  setActivePinia(createPinia())
  useGroupProjectStore().bomItems = [jobLine(activityType)]
  const wrapper: VueWrapper = mount(JobsTable, { global: { plugins: [i18n()] } })
  return wrapper.text().includes('ME 7 / TE 13')
}

function chainTreeShowsMeTe(activity: ChainActivity): boolean {
  const node = chainNode({ id: `node-${activity}`, stage: 'component', activity, runs: 1, meLevel: 7, teLevel: 13 })
  const wrapper = mount(ProductionChainJobTree, { props: { nodes: [node], selectedId: null }, global: { plugins: [i18n()] } })
  return wrapper.text().includes('ME 7 · TE 13')
}

describe('blueprint efficiency display', () => {
  it.each([
    ['manufacturing', true],
    ['invention', true],
    ['reaction', false],
    ['copy', false],
  ] as const)('shows ME/TE for a %s job of the jobs table: %s', (activity, shown) => {
    expect(jobsTableShowsMeTe(activity)).toBe(shown)
  })

  it.each([
    ['manufacturing', true],
    ['invention', true],
    ['reaction', false],
    ['copy', false],
  ] as const)('shows ME/TE for a %s node of the chain tree: %s', (activity, shown) => {
    expect(chainTreeShowsMeTe(activity)).toBe(shown)
  })

  it('keeps ME/TE for a line without activity', () => {
    expect(showsBlueprintEfficiency(null)).toBe(true)
  })
})
