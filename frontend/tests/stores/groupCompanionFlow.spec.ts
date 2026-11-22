import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import type { CharacterLocationRead, IntermediateStockProposal, PlacedAction, ProjectStationTransfer } from '@/stores/group-industry/types'
import {
  bomLine,
  characterLocation,
  companionProject,
  COMPANION_PROJECT_ID,
  placedAction,
  stationTransfer,
  stockProposal,
  stockProposalLine,
} from '../components/group-industry/companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const BOM_PATH = `${PROJECT_PATH}/bom`
const PLACE_ACTIONS_PATH = `${PROJECT_PATH}/place-actions`
const TRANSFERS_PATH = `${PROJECT_PATH}/station-transfers`
const PROPOSAL_PATH = `${PROJECT_PATH}/intermediate-stock-proposal`
const CONTRIBUTIONS_PATH = `${PROJECT_PATH}/contributions`
const LOCATIONS_PATH = '/me/characters/locations'
const ARRIVE_PATH = `${TRANSFERS_PATH}/transfer-morphite/arrive`
const FLOW_PATHS = [PLACE_ACTIONS_PATH, LOCATIONS_PATH, TRANSFERS_PATH, PROPOSAL_PATH]

let servedPlacedActions: PlacedAction[]
let servedPositions: CharacterLocationRead[]
let servedTransfers: ProjectStationTransfer[]
let servedProposal: IntermediateStockProposal
let failingPaths: Record<string, string>
let servedRole: 'owner' | 'admin' | 'member'

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

function loadsOf(paths: string[]): number[] {
  return paths.map(callsTo)
}

function loadsSince(paths: string[], before: number[]): number[] {
  return loadsOf(paths).map((count, index) => count - before[index])
}

beforeEach(() => {
  setActivePinia(createPinia())
  servedPlacedActions = [placedAction({ bomLineId: 'bom-morphite' })]
  servedPositions = [characterLocation()]
  servedTransfers = [stationTransfer({ id: 'transfer-morphite' })]
  servedProposal = stockProposal({ lines: [stockProposalLine({ bomItemId: 'bom-morphite', proposedQuantity: 300 })] })
  failingPaths = {}
  servedRole = 'owner'
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const failure = failingPaths[endpoint]
    if (failure !== undefined) throw new Error(failure)
    if (endpoint === PROJECT_PATH) return companionProject({ myRole: servedRole })
    if (endpoint === BOM_PATH) return [bomLine({ id: 'bom-morphite', typeId: 11_399, typeName: 'Morphite' })]
    if (endpoint === `${PROJECT_PATH}/chain`) return { id: COMPANION_PROJECT_ID, nodes: [] }
    if (endpoint === `${PROJECT_PATH}/cockpit`) return { priceAlert: null, undercutOrders: [] }
    if (endpoint === PLACE_ACTIONS_PATH) return servedPlacedActions
    if (endpoint === LOCATIONS_PATH) return servedPositions
    if (endpoint === TRANSFERS_PATH) return servedTransfers
    if (endpoint === PROPOSAL_PATH) return servedProposal
    return []
  })
})

describe('useGroupCompanionStore place and flow reads', () => {
  it('loads the place actions, the character positions, the station transfers and the stock proposal when opened', async () => {
    const store = useGroupCompanionStore()

    await store.open(COMPANION_PROJECT_ID)

    expect({
      placedActions: store.placedActions,
      positions: store.positions,
      transfers: store.transfers,
      stockProposal: store.stockProposal,
      loads: loadsOf(FLOW_PATHS),
    }).toEqual({
      placedActions: servedPlacedActions,
      positions: servedPositions,
      transfers: servedTransfers,
      stockProposal: servedProposal,
      loads: [1, 1, 1, 1],
    })
  })

  it.each([
    ['place actions', PLACE_ACTIONS_PATH, 'placedActions'],
    ['character positions', LOCATIONS_PATH, 'positions'],
    ['station transfers', TRANSFERS_PATH, 'transfers'],
    ['stock proposal', PROPOSAL_PATH, 'stockProposal'],
  ] as const)('leaves the %s unknown and reports the failure when that read fails', async (_label, path, field) => {
    failingPaths[path] = `Read of ${path} failed`
    const store = useGroupCompanionStore()

    await store.open(COMPANION_PROJECT_ID)

    expect({
      value: store[field],
      error: store.error,
      others: [store.placedActions, store.positions, store.transfers, store.stockProposal].filter(value => value !== null).length,
    }).toEqual({ value: null, error: `Read of ${path} failed`, others: 3 })
  })

  it('reads the positions and the place actions again on refresh, and nothing else', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const watched = [...FLOW_PATHS, BOM_PATH]
    const loadsAtOpen = loadsOf(watched)
    servedPositions = [characterLocation({ solarSystemId: 30_000_144, solarSystemName: 'Perimeter', stationId: null })]
    servedPlacedActions = [placedAction({ bomLineId: 'bom-morphite', here: false })]

    await store.refreshPlace()

    expect({
      loads: loadsSince(watched, loadsAtOpen),
      positions: store.positions?.map(position => position.solarSystemName),
      here: store.placedActions?.map(action => action.here),
    }).toEqual({ loads: [1, 1, 0, 0, 0], positions: ['Perimeter'], here: [false] })
  })
})

describe('useGroupCompanionStore stock proposal access', () => {
  it.each(['owner', 'member', 'admin'] as const)('reads the stock proposal of a project where I am %s', async (role) => {
    servedRole = role
    const store = useGroupCompanionStore()

    await store.open(COMPANION_PROJECT_ID)

    expect({ loads: callsTo(PROPOSAL_PATH), error: store.error }).toEqual({ loads: 1, error: null })
  })
})

describe('useGroupCompanionStore arrive and supply', () => {
  it('declares the arrival of a leg with an empty body then reads the transfers again', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    servedTransfers = [stationTransfer({ id: 'transfer-morphite', state: 'arrived', arrivedAt: '2026-11-21T13:00:00+00:00' })]

    await store.arriveTransfer('transfer-morphite')

    expect({
      posts: vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === ARRIVE_PATH),
      transferLoads: callsTo(TRANSFERS_PATH),
      states: store.transfers?.map(transfer => transfer.state),
    }).toEqual({
      posts: [[ARRIVE_PATH, { method: 'POST', body: '{}' }]],
      transferLoads: 2,
      states: ['arrived'],
    })
  })

  it('reports the refusal of an arrival and keeps the transfers as they were', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    failingPaths[ARRIVE_PATH] = 'The leg is already arrived'

    await store.arriveTransfer('transfer-morphite')

    expect({ error: store.error, states: store.transfers?.map(transfer => transfer.state) }).toEqual({
      error: 'The leg is already arrived',
      states: ['in_transit'],
    })
  })

  it('contributes the proposed quantity as a material then reads the BOM and the proposal again', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const watched = [BOM_PATH, PROPOSAL_PATH]
    const loadsAtOpen = loadsOf(watched)

    await store.supply('bom-morphite')

    const posts = vi.mocked(apiRequest).mock.calls.filter(([endpoint]) => endpoint === CONTRIBUTIONS_PATH)
    expect({
      endpoints: posts.map(([endpoint, options]) => [endpoint, options?.method]),
      bodies: posts.map(([, options]) => JSON.parse(String(options?.body))),
      loads: loadsSince(watched, loadsAtOpen),
    }).toEqual({
      endpoints: [[CONTRIBUTIONS_PATH, 'POST']],
      bodies: [{ bomItemId: 'bom-morphite', type: 'material', quantity: 300 }],
      loads: [1, 1],
    })
  })

  it('reports the failure of a supply', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    failingPaths[CONTRIBUTIONS_PATH] = 'Only the owner of a solo project can supply'

    await store.supply('bom-morphite')

    expect(store.error).toBe('Only the owner of a solo project can supply')
  })
})

describe('useGroupCompanionStore flow events', () => {
  it('reads the transfers again on a station transfer event and on reconnection', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const loadsAtOpen = callsTo(TRANSFERS_PATH)

    await store.handleEvents(['station_transfer_changed'])
    const afterTransferEvent = callsTo(TRANSFERS_PATH) - loadsAtOpen
    await store.handleEvent('reconnected')

    expect({ afterTransferEvent, afterReconnection: callsTo(TRANSFERS_PATH) - loadsAtOpen }).toEqual({ afterTransferEvent: 1, afterReconnection: 2 })
  })

  it('reads the place actions and the proposal again but not the transfers on a BOM event', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const watched = [PLACE_ACTIONS_PATH, PROPOSAL_PATH, TRANSFERS_PATH, LOCATIONS_PATH]
    const loadsAtOpen = loadsOf(watched)

    await store.handleEvents(['bom_updated'])

    expect(loadsSince(watched, loadsAtOpen)).toEqual([1, 1, 0, 0])
  })
})
