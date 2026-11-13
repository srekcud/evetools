import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import en from '@/i18n/locales/en.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

const EmptyView = { render: () => null }
const COPY = '[data-testid="multibuy-copy"]'
const EVALUATE = '[data-testid="multibuy-evaluate"]'
const TRITANIUM_AND_COVERED_MORPHITE_MULTIBUY = 'Tritanium\t1250000\nMorphite\t37'

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
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

const TRITANIUM_HALF_DELIVERED = material({
  id: 'bom-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 1_250_000,
  fulfilledQuantity: 500_000,
  remainingQuantity: 750_000,
  fulfillmentPercent: 40,
})

const MORPHITE_FULLY_COVERED = material({
  id: 'bom-morphite',
  typeId: 11399,
  typeName: 'Morphite',
  requiredQuantity: 37,
  fulfilledQuantity: 37,
  remainingQuantity: 0,
  fulfillmentPercent: 100,
  isFulfilled: true,
})

const RAM_JOB_LINE = material({ id: 'bom-ram', typeId: 11478, typeName: 'R.A.M.- Starship Tech', requiredQuantity: 300, isJob: true })

const SABRE_PROJECT = {
  id: 'project-sabre',
  name: 'Sabre doctrine',
  status: 'in_progress',
  shortLinkCode: 'sabre01',
  containerNames: [],
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
  private: false,
  notes: null,
  acceptsOutsideFavoriteSystem: false,
  excludesInvention: false,
  minimumOutsideSaving: 5,
  sellStructureId: null,
  sellStructureName: null,
} as GroupProject

function mountBomTable(bomItems: BomItem[]): { wrapper: VueWrapper; router: Router } {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = SABRE_PROJECT
  projectStore.bomItems = bomItems
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/appraisal', name: 'appraisal', component: EmptyView }] })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return { wrapper: mount(BomTable, { global: { plugins: [i18n, router] } }), router }
}

function control(wrapper: VueWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists(), selector).toBe(true)
  return found
}

enableAutoUnmount(afterEach)

describe('BomTable multibuy bridges', () => {
  let writeText: ReturnType<typeof vi.fn>

  beforeEach(() => {
    writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
  })

  it('copies the required quantity of every material line, covered ones included, and leaves the job lines out', async () => {
    const { wrapper } = mountBomTable([TRITANIUM_HALF_DELIVERED, RAM_JOB_LINE, MORPHITE_FULLY_COVERED])

    await control(wrapper, COPY).trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(TRITANIUM_AND_COVERED_MORPHITE_MULTIBUY)
  })

  it('sends the multibuy to the Appraisal page, and disables both buttons when there is no material line', async () => {
    const { wrapper, router } = mountBomTable([TRITANIUM_HALF_DELIVERED, MORPHITE_FULLY_COVERED])
    const push = vi.spyOn(router, 'push')

    await control(wrapper, EVALUATE).trigger('click')
    await flushPromises()

    expect({
      pendingText: useAppraisalHandoffStore().pendingText,
      pushed: push.mock.calls.map(([target]) => target),
    }).toEqual({
      pendingText: TRITANIUM_AND_COVERED_MORPHITE_MULTIBUY,
      pushed: [{ name: 'appraisal' }],
    })

    const empty = mountBomTable([RAM_JOB_LINE])
    expect({
      copyDisabled: control(empty.wrapper, COPY).attributes('disabled'),
      evaluateDisabled: control(empty.wrapper, EVALUATE).attributes('disabled'),
    }).toEqual({ copyDisabled: '', evaluateDisabled: '' })
  })
})
