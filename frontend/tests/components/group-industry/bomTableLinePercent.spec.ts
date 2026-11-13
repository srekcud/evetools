import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

const PROJECT_ID = 'project-line-percent'
const LINE_PERCENT = '[data-testid="line-fulfillment-percent"]'

function materialLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'line-tritanium',
    typeId: 34,
    typeName: 'Tritanium',
    requiredQuantity: 2368,
    fulfilledQuantity: 0,
    remainingQuantity: 2368,
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
    freeQuantity: 2368,
    containerQuantity: null,
    containerStockState: 'none',
    ...overrides,
  }
}

function percentCellText(line: BomItem): string {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Line percent', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
  projectStore.bomItems = [line]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BomTable, { global: { plugins: [i18n] } })
  return wrapper.get(LINE_PERCENT).text()
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('BomTable line fulfillment percent', () => {
  it('shows a whole number for the raw API percentage', () => {
    const text = percentCellText(materialLine({
      fulfilledQuantity: 2351,
      remainingQuantity: 17,
      fulfillmentPercent: 99.2820945945946,
    }))

    expect(text).toBe('99%')
  })

  it('never shows 100% on a line that is not filled', () => {
    const text = percentCellText(materialLine({
      fulfilledQuantity: 2362,
      remainingQuantity: 6,
      fulfillmentPercent: 99.7,
      isFulfilled: false,
    }))

    expect(text).toBe('99%')
  })

  it('rounds to the nearest whole number below the filled threshold', () => {
    const text = percentCellText(materialLine({
      fulfilledQuantity: 1200,
      remainingQuantity: 1168,
      fulfillmentPercent: 50.6,
    }))

    expect(text).toBe('51%')
  })

  it('shows 100% on a filled line', () => {
    const text = percentCellText(materialLine({
      fulfilledQuantity: 2368,
      remainingQuantity: 0,
      fulfillmentPercent: 100,
      isFulfilled: true,
    }))

    expect(text).toBe('100%')
  })

  it('keeps an empty line at 0%', () => {
    const text = percentCellText(materialLine({ fulfillmentPercent: 0 }))

    expect(text).toBe('0%')
  })

  it('reserves no fixed narrow width that lets the percentage overflow the cell', () => {
    const projectStore = useGroupProjectStore()
    projectStore.currentProject = { id: PROJECT_ID, name: 'Line percent', status: 'in_progress', myRole: 'owner', items: [] } as unknown as GroupProject
    projectStore.bomItems = [materialLine({ fulfillmentPercent: 99.2820945945946 })]
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const wrapper = mount(BomTable, { global: { plugins: [i18n] } })

    const classes = wrapper.get(LINE_PERCENT).classes()

    expect(classes).not.toContain('w-8')
    expect(classes).toContain('shrink-0')
  })
})
