import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import type { ProjectStationTransfer, ProjectTransportLegs } from '@/stores/group-industry/types'
import {
  ALT_EVE_CHARACTER_ID,
  amarrLegPlace,
  bomLine,
  companionProject,
  COMPANION_PROJECT_ID,
  MAIN_EVE_CHARACTER_ID,
  PERIMETER_STATION_ID,
  PERIMETER_SYSTEM_ID,
  projectTransportLegs,
  transportLeg,
  transportLegLine,
} from '../components/group-industry/companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const TRANSPORT_LEGS_PATH = `${PROJECT_PATH}/transport-legs`
const TRANSFERS_PATH = `${PROJECT_PATH}/station-transfers`
const UNKNOWN_ACCESS_KEY = 'amarr>perimeter'
const AMARR_SYSTEM_ID = 30_002_187
const AMARR_STATION_ID = 60_008_494

let servedLegs: ProjectTransportLegs
let servedTransfers: ProjectStationTransfer[]
let failingReads: Record<string, string>
let failingDeclaration: string | null

function readsOf(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint, options]) => calledEndpoint === endpoint && options?.method === undefined).length
}

function declarations() {
  return vi.mocked(apiRequest).mock.calls.filter(([endpoint, options]) => endpoint === TRANSFERS_PATH && options?.method === 'POST')
}

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().user = { characters: [{ eveCharacterId: MAIN_EVE_CHARACTER_ID, name: 'Alpha' }, { eveCharacterId: ALT_EVE_CHARACTER_ID, name: 'Beta' }] } as never
  servedLegs = projectTransportLegs({
    legs: [
      transportLeg(),
      transportLeg({
        key: UNKNOWN_ACCESS_KEY,
        departure: amarrLegPlace(),
        access: 'unknown',
        carrierCharacterIds: [],
        volume: 0.4,
        lines: [
          transportLegLine({ typeId: 35, typeName: 'Pyerite', quantity: 15, unitVolume: 0.01, volume: 0.15 }),
          transportLegLine({ typeId: 35, typeName: 'Pyerite', quantity: 25, state: 'in_production', readyAt: '2026-11-23T10:00:00+00:00', unitVolume: 0.01, volume: 0.25 }),
        ],
      }),
    ],
  })
  servedTransfers = []
  failingReads = {}
  failingDeclaration = null
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    const failure = failingReads[endpoint]
    if (failure !== undefined && options?.method === undefined) throw new Error(failure)
    if (endpoint === TRANSFERS_PATH && options?.method === 'POST' && failingDeclaration !== null) throw new Error(failingDeclaration)
    if (endpoint === PROJECT_PATH) return companionProject()
    if (endpoint === `${PROJECT_PATH}/bom`) return [bomLine({ id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite' })]
    if (endpoint === `${PROJECT_PATH}/chain`) return { id: COMPANION_PROJECT_ID, nodes: [] }
    if (endpoint === `${PROJECT_PATH}/cockpit`) return { priceAlert: null, undercutOrders: [] }
    if (endpoint === TRANSPORT_LEGS_PATH) return servedLegs
    if (endpoint === TRANSFERS_PATH && options?.method === undefined) return servedTransfers
    return []
  })
})

describe('useGroupCompanionStore transport legs', () => {
  it('reads the legs when opened and once for each batch of events, forgets them on close and leaves them unknown with the failure reported when the read fails', async () => {
    const store = useGroupCompanionStore()

    await store.open(COMPANION_PROJECT_ID)
    const atOpen = { legs: store.transportLegs, loads: readsOf(TRANSPORT_LEGS_PATH) }
    await store.handleEvents(['sale_recorded', 'station_transfer_changed'])
    const afterBatch = readsOf(TRANSPORT_LEGS_PATH)
    failingReads[TRANSPORT_LEGS_PATH] = 'Read of the transport legs failed'
    await store.handleEvents(['bom_updated'])
    const afterFailure = { legs: store.transportLegs, error: store.error }
    failingReads = {}
    await store.handleEvents(['bom_updated'])
    const reloaded = store.transportLegs
    store.close()

    expect({ atOpen, afterBatch, afterFailure, reloaded, closed: store.transportLegs }).toEqual({
      atOpen: { legs: servedLegs, loads: 1 },
      afterBatch: 2,
      afterFailure: { legs: null, error: 'Read of the transport legs failed' },
      reloaded: servedLegs,
      closed: null,
    })
  })

  it('declares the leg with the ready lines and my first character when the access is unknown, then reads the transfers and the legs again', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const transferLoadsAtOpen = readsOf(TRANSFERS_PATH)
    const legLoadsAtOpen = readsOf(TRANSPORT_LEGS_PATH)

    await store.declareLeg(UNKNOWN_ACCESS_KEY)

    expect({
      posts: declarations().map(([endpoint, options]) => [endpoint, JSON.parse(String(options?.body))]),
      transferLoads: readsOf(TRANSFERS_PATH) - transferLoadsAtOpen,
      legLoads: readsOf(TRANSPORT_LEGS_PATH) - legLoadsAtOpen,
      error: store.error,
    }).toEqual({
      posts: [[TRANSFERS_PATH, {
        fromSolarSystemId: AMARR_SYSTEM_ID,
        fromLocationId: AMARR_STATION_ID,
        toSolarSystemId: PERIMETER_SYSTEM_ID,
        toLocationId: PERIMETER_STATION_ID,
        lines: [{ typeId: 35, quantity: 15 }],
        carrierCharacterId: MAIN_EVE_CHARACTER_ID,
        contractId: null,
      }]],
      transferLoads: 1,
      legLoads: 1,
      error: null,
    })
  })

  it('reports an unknown leg without calling the API and reports the refusal of a declaration without throwing', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)

    await store.declareLeg('unknown-leg')
    const unknownLeg = { error: store.error, posts: declarations().length }
    failingDeclaration = 'A carrier must be one of your characters'
    await expect(store.declareLeg(UNKNOWN_ACCESS_KEY)).resolves.toBeUndefined()

    expect({ unknownLeg, refusal: { error: store.error, posts: declarations().length } }).toEqual({
      unknownLeg: { error: 'Unknown transport leg', posts: 0 },
      refusal: { error: 'A carrier must be one of your characters', posts: 1 },
    })
  })
})
