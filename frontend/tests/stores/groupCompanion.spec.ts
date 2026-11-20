import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { useSyncStore } from '@/stores/sync'
import type { BomItem, ChainNode, ProjectPriceAlert, ProjectSellOrder } from '@/stores/group-industry/types'
import { bomLine, companionProject, COMPANION_PROJECT_ID } from '../components/group-industry/companionFixtures'
import { chainNode } from '../productionChainNodes'
import { sellOrder } from '../sellOrderFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const BOM_PATH = `${PROJECT_PATH}/bom`
const COCKPIT_PATH = `${PROJECT_PATH}/cockpit`
const CHAIN_PATH = `${PROJECT_PATH}/chain`
const RESERVATION_PATH = `${BOM_PATH}/bom-nocxium/reservation`

const PRICE_ALERT: ProjectPriceAlert = {
  typeId: 11_993,
  typeName: 'Hammerhead II',
  quantity: 6,
  jitaUnitPrice: 690_000,
  breakEvenPerUnit: 760_000,
  gapPerUnit: 70_000,
}

let currentBom: BomItem[]
let currentPriceAlert: ProjectPriceAlert | null
let currentUndercutOrders: ProjectSellOrder[]
let currentChainNodes: ChainNode[]
let chainFails: boolean

function nocxium(overrides: Partial<BomItem> = {}): BomItem {
  return bomLine({
    id: 'bom-nocxium',
    typeId: 38,
    typeName: 'Nocxium',
    requiredQuantity: 100,
    remainingQuantity: 100,
    freeQuantity: 80,
    reservedQuantity: 20,
    ...overrides,
  })
}

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  currentBom = [nocxium()]
  currentPriceAlert = null
  currentUndercutOrders = []
  currentChainNodes = []
  chainFails = false
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === PROJECT_PATH) return companionProject()
    if (endpoint === BOM_PATH) return currentBom
    if (endpoint === COCKPIT_PATH) return { priceAlert: currentPriceAlert, undercutOrders: currentUndercutOrders }
    if (endpoint === CHAIN_PATH) {
      if (chainFails) throw new Error('Chain unavailable')
      return { id: COMPANION_PROJECT_ID, nodes: currentChainNodes }
    }
    return []
  })
})

describe('useGroupCompanionStore', () => {
  it('reserves the whole free quantity of the line then reloads the BOM', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    await store.claim('bom-nocxium')

    expect({
      reserved: vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === RESERVATION_PATH),
      bomLoads: callsTo(BOM_PATH),
    }).toEqual({
      reserved: [[RESERVATION_PATH, { method: 'POST', body: JSON.stringify({ quantity: 80 }) }]],
      bomLoads: 2,
    })
  })

  it('reloads the BOM and the project on a line reserved event', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    currentBom = [nocxium({ freeQuantity: 50, reservedQuantity: 50 })]

    await store.handleEvent('line_reserved')

    expect({
      reservedQuantities: store.bomItems.map(item => item.reservedQuantity),
      bomLoads: callsTo(BOM_PATH),
      projectLoads: callsTo(PROJECT_PATH),
    }).toEqual({ reservedQuantities: [50], bomLoads: 2, projectLoads: 2 })
  })

  it('keeps the price alert of the cockpit when opened and refreshes it on every project event, dropping it once Jita is back above the break-even', async () => {
    currentPriceAlert = PRICE_ALERT
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const opened = store.priceAlert
    currentPriceAlert = null

    await store.handleEvent('sale_recorded')

    expect({ opened, refreshed: store.priceAlert, cockpitLoads: callsTo(COCKPIT_PATH) }).toEqual({
      opened: PRICE_ALERT,
      refreshed: null,
      cockpitLoads: 2,
    })
  })

  it('forgets the price alert when the companion is closed', async () => {
    currentPriceAlert = PRICE_ALERT
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    store.close()

    expect(store.priceAlert).toBeNull()
  })

  it('keeps the undercut sell orders of the cockpit when opened and refreshes them on every project event, dropping them once nothing is undercut', async () => {
    currentUndercutOrders = [sellOrder()]
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const opened = store.undercutOrders
    currentUndercutOrders = []

    await store.handleEvent('sale_recorded')

    expect({ opened, refreshed: store.undercutOrders, cockpitLoads: callsTo(COCKPIT_PATH) }).toEqual({
      opened: [sellOrder()],
      refreshed: [],
      cockpitLoads: 2,
    })
  })

  it('forgets the undercut sell orders when the companion is closed', async () => {
    currentUndercutOrders = [sellOrder()]
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    store.close()

    expect(store.undercutOrders).toEqual([])
  })

  it('refreshes the undercut sell orders when a character orders sync completes, and ignores the other syncs', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    currentUndercutOrders = [sellOrder()]
    const syncStore = useSyncStore()

    syncStore.syncStatus['blueprints'] = { syncType: 'blueprints', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-10T12:16:00+00:00' }
    await vi.waitFor(() => expect(callsTo(COCKPIT_PATH)).toBe(1))
    const afterOtherSync = store.undercutOrders
    syncStore.syncStatus['character-orders'] = { syncType: 'character-orders', status: 'completed', progress: 100, message: null, data: null, timestamp: '2026-11-10T12:20:00+00:00' }
    await vi.waitFor(() => expect(store.undercutOrders).toEqual([sellOrder()]))

    expect({ afterOtherSync, cockpitLoads: callsTo(COCKPIT_PATH) }).toEqual({ afterOtherSync: [], cockpitLoads: 2 })
  })
  it('loads the production chain with the project and keeps its nodes in the order served', async () => {
    currentChainNodes = [
      chainNode({ id: 'bom-capacitor', stage: 'component', status: 'todo' }),
      chainNode({ id: 'bom-ishtar', stage: 'product', status: 'blocked' }),
    ]
    const store = useGroupCompanionStore()

    await store.open(COMPANION_PROJECT_ID)

    expect(store.chainNodes?.map(node => node.id)).toEqual(['bom-capacitor', 'bom-ishtar'])
  })

  it('reloads the chain on a project event, and forgets it when the companion is closed', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    currentChainNodes = [chainNode({ id: 'bom-hull', stage: 'component', status: 'active' })]

    await store.handleEvent('line_reserved')
    const reloaded = store.chainNodes?.map(node => node.id)
    store.close()

    expect({ reloaded, chainLoads: callsTo(CHAIN_PATH), afterClose: store.chainNodes }).toEqual({
      reloaded: ['bom-hull'],
      chainLoads: 2,
      afterClose: null,
    })
  })

  it('keeps the chain unknown and reports the failure when the chain cannot be loaded', async () => {
    chainFails = true
    const store = useGroupCompanionStore()

    await store.open(COMPANION_PROJECT_ID)

    expect({ chainNodes: store.chainNodes, error: store.error, bomLoaded: store.bomItems.length }).toEqual({
      chainNodes: null,
      error: 'Chain unavailable',
      bomLoaded: 1,
    })
  })
})
