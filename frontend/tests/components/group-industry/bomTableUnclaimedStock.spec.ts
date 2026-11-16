import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

const PROJECT_ID = 'project-ram-batch'
const BADGE = '[data-testid="unclaimed-stock-badge"]'
const BADGE_VALUE = '[data-testid="unclaimed-stock-value"]'
const BADGE_PRICE_UNKNOWN = '[data-testid="unclaimed-stock-launch-price-unknown"]'

function materialLine(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'line-ram',
    typeId: 11478,
    typeName: 'R.A.M.- Starship Tech',
    requiredQuantity: 100,
    fulfilledQuantity: 80,
    remainingQuantity: 20,
    fulfillmentPercent: 80,
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
    freeQuantity: 20,
    containerQuantity: 80,
    containerStockState: 'known',
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

describe('BomTable unclaimed stock badge', () => {
  it('shows the unclaimed quantity and its value at the frozen launch price', () => {
    const wrapper = mountBomWith([materialLine({ unclaimedQuantity: 80, launchPrice: 12.5, unclaimedValue: 1000 })])

    expect(wrapper.get(BADGE).text()).toContain('80')
    expect(wrapper.find(BADGE_VALUE).exists()).toBe(true)
    expect(wrapper.find(BADGE_PRICE_UNKNOWN).exists()).toBe(false)
  })

  it('says the launch price is unknown instead of showing a value when none was frozen', () => {
    const wrapper = mountBomWith([materialLine({ unclaimedQuantity: 80, launchPrice: null, unclaimedValue: null })])

    expect(wrapper.get(BADGE).text()).toContain('80')
    expect(wrapper.find(BADGE_PRICE_UNKNOWN).exists()).toBe(true)
    expect(wrapper.find(BADGE_VALUE).exists()).toBe(false)
  })

  it('shows no badge when nothing is unclaimed', () => {
    const wrapper = mountBomWith([materialLine({ unclaimedQuantity: 0, launchPrice: 12.5, unclaimedValue: 0 })])

    expect(wrapper.find(BADGE).exists()).toBe(false)
  })

  it('shows no badge when the unclaimed quantity is unknown or absent', () => {
    const unknown = mountBomWith([materialLine({ unclaimedQuantity: null })])
    const absent = mountBomWith([materialLine({})])

    expect(unknown.find(BADGE).exists()).toBe(false)
    expect(absent.find(BADGE).exists()).toBe(false)
  })

  it('is read only: the badge carries no button', () => {
    const wrapper = mountBomWith([materialLine({ unclaimedQuantity: 80, launchPrice: 12.5, unclaimedValue: 1000 })])

    expect(wrapper.get(BADGE).find('button').exists()).toBe(false)
  })

  it('leaves the container fulfilment display untouched', () => {
    const wrapper = mountBomWith([materialLine({ unclaimedQuantity: 80, launchPrice: 12.5, unclaimedValue: 1000 })])

    expect(wrapper.get('[data-testid="fulfilled-quantity"]').text()).toContain('80')
    expect(wrapper.find('[data-testid="container-stock-unknown"]').exists()).toBe(false)
  })
})
