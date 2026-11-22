import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupStationTransfersStore } from '@/stores/group-industry/stationTransfers'
import type { DeclareStationTransferInput, ProjectStationTransfer } from '@/stores/group-industry/types'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_PROJECT_ID = 'project-ishtar-transfers'
const HECATE_PROJECT_ID = 'project-hecate-transfers'
const ZYDRINE = 39

const COURIER_LEG_WITH_UNREADABLE_ARRIVAL: ProjectStationTransfer = {
  id: 'leg-courier',
  routeId: 'route-jita-azbel',
  legIndex: 0,
  legCount: 2,
  from: { solarSystemId: 30_000_142, solarSystemName: 'Jita', locationId: 60_003_760, stationName: null },
  to: { solarSystemId: 30_002_000, solarSystemName: 'C-J6MT', locationId: null, stationName: null },
  carrier: { kind: 'courier', characterId: 9001, contractId: null },
  declaredBy: 'Kaelen',
  declaredAt: '2026-11-23T09:00:00+00:00',
  expectedArrivalAt: '2026-11-26T09:00:00+00:00',
  arrivedAt: null,
  state: 'unknown',
  volume: null,
  unknownReason: 'volume_unknown',
  lines: [
    {
      typeId: ZYDRINE,
      typeName: 'Zydrine',
      quantity: 100,
      volume: null,
      departed: 100,
      arrived: null,
      inTransit: null,
      departureSource: 'declared',
      arrivalSource: 'pending',
      unknownReason: 'corporation_assets_not_synced',
    },
  ],
}

const DECLARATION: DeclareStationTransferInput = {
  fromSolarSystemId: 30_000_142,
  fromLocationId: 60_003_760,
  toSolarSystemId: 30_002_100,
  toLocationId: 1_040_000_000_002,
  lines: [{ typeId: ZYDRINE, quantity: 100 }],
  carrierCharacterId: 9001,
  contractId: null,
}

function transfersPath(projectId: string): string {
  return `/industry/projects/${projectId}/station-transfers`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group station transfers store', () => {
  it('reads the transfers of the project and keeps unknown quantities and volumes as null', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupStationTransfersStore()

    void store.fetchStationTransfers(ISHTAR_PROJECT_ID)
    const whileLoading = { loading: store.loading, transfers: store.transfers }
    pending.resolve([COURIER_LEG_WITH_UNREADABLE_ARRIVAL])
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(transfersPath(ISHTAR_PROJECT_ID))
    expect(whileLoading).toEqual({ loading: true, transfers: [] })
    expect({ loading: store.loading, error: store.error, transfers: store.transfers }).toEqual({
      loading: false,
      error: null,
      transfers: [COURIER_LEG_WITH_UNREADABLE_ARRIVAL],
    })
  })

  it('ignores the late answer of a project that is no longer the one being read', async () => {
    const ishtarAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(ishtarAnswer.promise)
    vi.mocked(apiRequest).mockResolvedValueOnce([])
    const store = useGroupStationTransfersStore()

    void store.fetchStationTransfers(ISHTAR_PROJECT_ID)
    await store.fetchStationTransfers(HECATE_PROJECT_ID)
    ishtarAnswer.resolve([COURIER_LEG_WITH_UNREADABLE_ARRIVAL])
    await flushPromises()

    expect(store.transfers).toEqual([])
  })

  it('declares a transfer with a POST then reloads the transfers of the project', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(COURIER_LEG_WITH_UNREADABLE_ARRIVAL)
    vi.mocked(apiRequest).mockResolvedValueOnce([COURIER_LEG_WITH_UNREADABLE_ARRIVAL])
    const store = useGroupStationTransfersStore()

    await store.declareStationTransfer(ISHTAR_PROJECT_ID, DECLARATION)

    expect(vi.mocked(apiRequest).mock.calls).toEqual([
      [transfersPath(ISHTAR_PROJECT_ID), { method: 'POST', body: JSON.stringify(DECLARATION) }],
      [transfersPath(ISHTAR_PROJECT_ID)],
    ])
    expect(store.transfers).toEqual([COURIER_LEG_WITH_UNREADABLE_ARRIVAL])
  })

  it('marks a leg as arrived and deletes a transfer with the matching requests, each followed by a reload', async () => {
    vi.mocked(apiRequest).mockResolvedValue([])
    const store = useGroupStationTransfersStore()

    await store.markStationTransferArrived(ISHTAR_PROJECT_ID, 'leg-courier')
    await store.deleteStationTransfer(ISHTAR_PROJECT_ID, 'leg-courier')

    expect(vi.mocked(apiRequest).mock.calls).toEqual([
      [`${transfersPath(ISHTAR_PROJECT_ID)}/leg-courier/arrive`, { method: 'POST', body: '{}' }],
      [transfersPath(ISHTAR_PROJECT_ID)],
      [`${transfersPath(ISHTAR_PROJECT_ID)}/leg-courier`, { method: 'DELETE' }],
      [transfersPath(ISHTAR_PROJECT_ID)],
    ])
  })
})
