import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

const PROJECT_ID = 'project-ram-batch'
const CONTAINER_STOCK_UNKNOWN = '[data-testid="container-stock-unknown"]'
const FULFILLED_CELL = '[data-testid="fulfilled-quantity"]'

function materialLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'line-ram',
    typeId: 11478,
    typeName: 'R.A.M.- Starship Tech',
    requiredQuantity: 55,
    fulfilledQuantity: 0,
    remainingQuantity: 55,
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
    reservedQuantity: 0,
    freeQuantity: 55,
    containerQuantity: null,
    containerStockState: 'none',
    ...overrides,
  }
}

function mountBomWith(lines: BomItem[]) {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'RAM batch', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
  projectStore.bomItems = lines
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n] } })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BomTable container stock', () => {
  it('shows the container stock as fulfilled and the matching remaining quantity', () => {
    const wrapper = mountBomWith([materialLine({
      fulfilledQuantity: 2,
      remainingQuantity: 53,
      fulfillmentPercent: 3.64,
      containerQuantity: 2,
      containerStockState: 'known',
    })])

    expect(wrapper.get(FULFILLED_CELL).text()).toContain('2')
    expect(wrapper.get(FULFILLED_CELL).text()).not.toContain('≥')
    expect(wrapper.find(CONTAINER_STOCK_UNKNOWN).exists()).toBe(false)
    expect(wrapper.text()).toContain('53')
  })

  it('flags an unreadable container and shows the fulfilled quantity as a lower bound, never as a plain zero', () => {
    const wrapper = mountBomWith([materialLine({ containerStockState: 'unknown' })])

    expect(wrapper.find(CONTAINER_STOCK_UNKNOWN).exists()).toBe(true)
    expect(wrapper.get(FULFILLED_CELL).text()).toContain('≥')
  })

  it('shows no container mark on a project without container', () => {
    const wrapper = mountBomWith([materialLine({ fulfilledQuantity: 10, remainingQuantity: 45 })])

    expect(wrapper.find(CONTAINER_STOCK_UNKNOWN).exists()).toBe(false)
    expect(wrapper.get(FULFILLED_CELL).text()).not.toContain('≥')
  })
})
