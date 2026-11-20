import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupMemberRole, GroupProject, ProjectPurchase } from '@/stores/group-industry/types'
import { present } from '../../present'
import { callsTo, mountProductionJobRows } from '../../productionJobRows'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-titan-batch'
const BOM_URL = `/industry/projects/${PROJECT_ID}/bom`
const SUGGESTIONS_URL = `/industry/projects/${PROJECT_ID}/purchase-suggestions`
const PLATES_LINE_ID = 'job-capital-armor-plates'
const PURCHASES_URL = `/industry/projects/${PROJECT_ID}/bom/${PLATES_LINE_ID}/purchases`
const DELETE_SECOND_PURCHASE_URL = `/industry/projects/${PROJECT_ID}/purchases/purchase-manual`
const OPEN_PURCHASES = '[data-testid="purchases-open"]'
const SUGGESTION = '[data-testid="purchase-suggestion"]'
const SUGGESTION_LINKED = '[data-testid="purchase-suggestion-linked"]'
const SUGGESTION_LINK = '[data-testid="purchase-suggestion-link"]'
const MANUAL_QUANTITY = '[data-testid="manual-purchase-quantity"]'
const MANUAL_UNIT_PRICE = '[data-testid="manual-purchase-unit-price"]'
const MANUAL_SUBMIT = '[data-testid="manual-purchase-submit"]'
const PURCHASE = '[data-testid="purchase"]'
const PURCHASE_DELETE = '[data-testid="purchase-delete"]'
const PURCHASE_ERROR = '[data-testid="purchase-error"]'
const PURCHASE_REFUSED = 'Quantity exceeds the quantity required by the line'
const PLATES_TYPE_ID = 21010

function purchase(overrides: Partial<ProjectPurchase>): ProjectPurchase {
  return {
    id: 'purchase',
    bomLineId: PLATES_LINE_ID,
    typeId: PLATES_TYPE_ID,
    quantity: 0,
    unitPrice: 0,
    totalPrice: 0,
    source: 'transaction',
    transactionId: null,
    createdAt: '2026-11-06T09:00:00+00:00',
    isMine: false,
    buyerName: null,
    ...overrides,
  }
}

const CAPITAL_ARMOR_PLATES: BomItem = {
  id: PLATES_LINE_ID,
  typeId: PLATES_TYPE_ID,
  typeName: 'Capital Armor Plates',
  requiredQuantity: 60,
  fulfilledQuantity: 0,
  remainingQuantity: 60,
  fulfillmentPercent: 0,
  estimatedPrice: null,
  estimatedTotal: null,
  isJob: true,
  jobGroup: 'component',
  activityType: 'manufacturing',
  parentTypeId: null,
  meLevel: 10,
  teLevel: 20,
  runs: 60,
  isFulfilled: false,
  jobMatches: [],
  matchedRuns: 0,
  purchasedQuantity: 50,
  reservations: [],
  pendingContributions: [],
  reservedQuantity: 0,
  freeQuantity: 0,
  purchases: [
    purchase({ id: 'purchase-transaction', quantity: 40, unitPrice: 1_150_000, totalPrice: 46_000_000, transactionId: 'transaction-uuid-1' }),
    purchase({ id: 'purchase-manual', quantity: 10, unitPrice: 1_200_000, totalPrice: 12_000_000, source: 'manual' }),
  ],
}

const PYERITE: BomItem = {
  ...CAPITAL_ARMOR_PLATES,
  id: 'bom-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  isJob: false,
  jobGroup: null,
  activityType: null,
  requiredQuantity: 45_000,
  purchasedQuantity: 15_000,
  reservations: [],
  pendingContributions: [],
  reservedQuantity: 0,
  freeQuantity: 0,
  purchases: [purchase({ id: 'purchase-pyerite', bomLineId: 'bom-pyerite', typeId: 35, quantity: 15_000, unitPrice: 9, totalPrice: 135_000 })],
}

const NEXT_SUGGESTION_UUID = '0192f0c4-7b1e-7a3c-9d2e-205000000201'

const SUGGESTIONS = {
  totalCount: 3,
  walletReadings: [],
  suggestions: [
    {
      transactionId: 6_400_000_201,
      transactionUuid: NEXT_SUGGESTION_UUID,
      typeId: PLATES_TYPE_ID,
      typeName: 'Capital Armor Plates',
      quantity: 12,
      unitPrice: 980_000,
      totalPrice: 11_760_000,
      date: '2026-11-05T18:45:00+00:00',
      characterName: 'Aria Solberg',
      locationId: 60003760,
      alreadyLinked: false,
    },
    {
      transactionId: 6_400_000_202,
      transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-205000000202',
      typeId: PLATES_TYPE_ID,
      typeName: 'Capital Armor Plates',
      quantity: 8,
      unitPrice: 1_020_000,
      totalPrice: 8_160_000,
      date: '2026-11-04T12:10:00+00:00',
      characterName: 'Brann Holt',
      locationId: 60003760,
      alreadyLinked: true,
    },
    {
      transactionId: 6_400_000_203,
      transactionUuid: '0192f0c4-7b1e-7a3c-9d2e-205000000203',
      typeId: 21011,
      typeName: 'Capital Shield Emitter',
      quantity: 3,
      unitPrice: 4_300_000,
      totalPrice: 12_900_000,
      date: '2026-11-03T09:30:00+00:00',
      characterName: 'Cyra Lune',
      locationId: 60003760,
      alreadyLinked: false,
    },
  ],
}

function projectAs(myRole: GroupMemberRole): GroupProject {
  return { id: PROJECT_ID, name: 'Titan batch', status: 'in_progress', myRole, items: [] } as unknown as GroupProject
}

function mountBomTable(myRole: GroupMemberRole, lines: BomItem[]): VueWrapper {
  setActivePinia(createPinia())
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = projectAs(myRole)
  projectStore.bomItems = lines
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  return mount(BomTable, { global: { plugins: [i18n], stubs: { teleport: true } } })
}

function mountRows(myRole: GroupMemberRole, lines: BomItem[]): Promise<VueWrapper> {
  return mountProductionJobRows({ lines, projectId: PROJECT_ID, role: myRole, answers: { [SUGGESTIONS_URL]: SUGGESTIONS } })
}

function sentBody(url: string): unknown {
  const call = vi.mocked(apiRequest).mock.calls.find(([calledUrl]) => calledUrl === url)
  expect(call, `request to ${url}`).toBeDefined()
  return JSON.parse(String(present(present(call)[1]).body))
}

async function openPurchases(wrapper: VueWrapper) {
  await wrapper.get(OPEN_PURCHASES).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
})

describe('Production job row purchases', () => {
  it('suggests the transactions of the line type, flags the one already linked and links the chosen transaction', async () => {
    const wrapper = await mountRows('owner', [CAPITAL_ARMOR_PLATES])

    await openPurchases(wrapper)

    expect(callsTo(SUGGESTIONS_URL)).toBe(1)
    const suggestions = wrapper.findAll(SUGGESTION)
    expect(suggestions).toHaveLength(2)
    expect(suggestions[0].text()).toContain('Aria Solberg')
    expect(suggestions[0].find(SUGGESTION_LINKED).exists()).toBe(false)
    expect(suggestions[1].text()).toContain('Brann Holt')
    expect(suggestions[1].find(SUGGESTION_LINKED).exists()).toBe(true)

    await suggestions[0].get(SUGGESTION_LINK).trigger('click')
    await flushPromises()

    expect(sentBody(PURCHASES_URL)).toEqual({ transactionId: NEXT_SUGGESTION_UUID })
    expect(callsTo(BOM_URL)).toBe(1)
  })

  it('records a manual purchase of the line type with its quantity and unit price, and shows the message of a refused one', async () => {
    const wrapper = await mountRows('owner', [CAPITAL_ARMOR_PLATES])
    await openPurchases(wrapper)

    await wrapper.get(MANUAL_QUANTITY).setValue('15')
    await wrapper.get(MANUAL_UNIT_PRICE).setValue('1080000')
    await wrapper.get(MANUAL_SUBMIT).trigger('click')
    await flushPromises()

    expect(sentBody(PURCHASES_URL)).toEqual({ typeId: PLATES_TYPE_ID, quantity: 15, unitPrice: 1_080_000 })
    expect(callsTo(BOM_URL)).toBe(1)
    expect(wrapper.find(PURCHASE_ERROR).exists()).toBe(false)

    vi.mocked(apiRequest).mockRejectedValue(new Error(PURCHASE_REFUSED))
    await wrapper.get(MANUAL_QUANTITY).setValue('90')
    await wrapper.get(MANUAL_UNIT_PRICE).setValue('1080000')
    await wrapper.get(MANUAL_SUBMIT).trigger('click')
    await flushPromises()

    expect(wrapper.get(PURCHASE_ERROR).text()).toBe(PURCHASE_REFUSED)
    expect(wrapper.get(PURCHASE_ERROR).attributes('role')).toBe('alert')
    expect(callsTo(BOM_URL)).toBe(1)
  })

  it('deletes a purchase of the line then reloads the BOM', async () => {
    const wrapper = await mountRows('owner', [CAPITAL_ARMOR_PLATES])

    expect(wrapper.findAll(PURCHASE)).toHaveLength(2)
    await wrapper.findAll(PURCHASE)[1].get(PURCHASE_DELETE).trigger('click')
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith(DELETE_SECOND_PURCHASE_URL, { method: 'DELETE' })
    expect(callsTo(BOM_URL)).toBe(1)
  })

  it('offers the purchase screen to every participant and the deletion of a purchase to the owner or to its buyer only, on component lines and on material lines', async () => {
    const actionsOf = async (myRole: GroupMemberRole) => {
      const components = await mountRows(myRole, [CAPITAL_ARMOR_PLATES])
      const componentActions = { componentOpen: components.findAll(OPEN_PURCHASES).length, componentDelete: components.findAll(PURCHASE_DELETE).length }
      const materials = mountBomTable(myRole, [PYERITE])
      return {
        ...componentActions,
        materialOpen: materials.findAll(OPEN_PURCHASES).length,
        materialDelete: materials.findAll(PURCHASE_DELETE).length,
      }
    }

    expect({ owner: await actionsOf('owner'), admin: await actionsOf('admin'), member: await actionsOf('member') }).toEqual({
      owner: { componentOpen: 1, componentDelete: 2, materialOpen: 1, materialDelete: 1 },
      admin: { componentOpen: 1, componentDelete: 0, materialOpen: 1, materialDelete: 0 },
      member: { componentOpen: 1, componentDelete: 0, materialOpen: 1, materialDelete: 0 },
    })
  })
})
