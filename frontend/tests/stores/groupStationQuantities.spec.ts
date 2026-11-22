import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupStationQuantitiesStore } from '@/stores/group-industry/stationQuantities'
import type { TypeStationQuantities } from '@/stores/group-industry/types'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_PROJECT_ID = 'project-ishtar-stations'
const HECATE_PROJECT_ID = 'project-hecate-stations'
const ZYDRINE = 39

const ZYDRINE_AT_AZBEL: TypeStationQuantities = {
  typeId: ZYDRINE,
  typeName: 'Zydrine',
  lineIds: ['line-zydrine'],
  unknownReason: null,
  stations: [
    {
      locationId: 1_040_000_000_002,
      stationName: 'Azbel',
      needed: 100,
      consumed: 0,
      inProduction: 0,
      onSite: 60,
      out: 0,
      toBring: 40,
      inTransit: null,
      unknownReason: null,
    },
  ],
}

const PYERITE_WITH_UNREADABLE_CONTAINER: TypeStationQuantities = {
  typeId: 35,
  typeName: 'Pyerite',
  lineIds: ['line-pyerite'],
  unknownReason: null,
  stations: [
    {
      locationId: 1_040_000_000_001,
      stationName: 'Raitaru',
      needed: 80,
      consumed: 0,
      inProduction: 0,
      onSite: null,
      out: null,
      toBring: null,
      inTransit: null,
      unknownReason: 'corporation_assets_not_synced',
    },
  ],
}

function stationQuantitiesPath(projectId: string): string {
  return `/industry/projects/${projectId}/station-quantities`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group station quantities store', () => {
  it('reads the quantities of the project and keeps the unreadable ones as null with their reason', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupStationQuantitiesStore()

    void store.fetchStationQuantities(ISHTAR_PROJECT_ID)
    const whileLoading = { loading: store.loading, quantities: store.quantities }
    pending.resolve([ZYDRINE_AT_AZBEL, PYERITE_WITH_UNREADABLE_CONTAINER])
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(stationQuantitiesPath(ISHTAR_PROJECT_ID))
    expect(whileLoading).toEqual({ loading: true, quantities: [] })
    expect({ loading: store.loading, error: store.error, quantities: store.quantities }).toEqual({
      loading: false,
      error: null,
      quantities: [ZYDRINE_AT_AZBEL, PYERITE_WITH_UNREADABLE_CONTAINER],
    })
  })

  it('exposes the API error instead of empty quantities when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Station quantities unavailable: project not found'))
    const store = useGroupStationQuantitiesStore()

    await store.fetchStationQuantities(ISHTAR_PROJECT_ID)

    expect({ loading: store.loading, error: store.error, quantities: store.quantities }).toEqual({
      loading: false,
      error: 'Station quantities unavailable: project not found',
      quantities: [],
    })
  })

  it('ignores the late answer of a project that is no longer the one being read', async () => {
    const ishtarAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(ishtarAnswer.promise)
    vi.mocked(apiRequest).mockResolvedValueOnce([PYERITE_WITH_UNREADABLE_CONTAINER])
    const store = useGroupStationQuantitiesStore()

    void store.fetchStationQuantities(ISHTAR_PROJECT_ID)
    await store.fetchStationQuantities(HECATE_PROJECT_ID)
    ishtarAnswer.resolve([ZYDRINE_AT_AZBEL])
    await flushPromises()

    expect(store.quantities).toEqual([PYERITE_WITH_UNREADABLE_CONTAINER])
  })

  it('forgets the quantities of the previous project while the next one loads', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([ZYDRINE_AT_AZBEL])
    const store = useGroupStationQuantitiesStore()
    await store.fetchStationQuantities(ISHTAR_PROJECT_ID)
    const hecateAnswer = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(hecateAnswer.promise)

    void store.fetchStationQuantities(HECATE_PROJECT_ID)

    expect(store.quantities).toEqual([])
  })
})
