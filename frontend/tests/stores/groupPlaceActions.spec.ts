import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupPlaceActionsStore } from '@/stores/group-industry/placeActions'
import type { PlacedAction } from '@/stores/group-industry/types'
import { deferred } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_PROJECT_ID = 'project-ishtar-places'

const LAUNCH_RAM_AT_AZBEL: PlacedAction = {
  id: `${ISHTAR_PROJECT_ID}:launch_job:line-ram`,
  projectId: ISHTAR_PROJECT_ID,
  projectName: 'Ishtar batch',
  code: 'launch_job',
  bomLineId: 'line-ram',
  typeId: 11478,
  typeName: 'R.A.M.- Starship Tech',
  quantity: 5,
  place: {
    kind: 'station',
    locationId: 1_040_000_000_002,
    name: 'Azbel',
    solarSystemId: 30_000_144,
    solarSystemName: 'Perimeter',
    unknownReason: null,
  },
  here: true,
  access: null,
}

const SOURCE_ZYDRINE_UNKNOWN_PLACE: PlacedAction = {
  id: `${ISHTAR_PROJECT_ID}:source_material:line-zydrine`,
  projectId: ISHTAR_PROJECT_ID,
  projectName: 'Ishtar batch',
  code: 'source_material',
  bomLineId: 'line-zydrine',
  typeId: 39,
  typeName: 'Zydrine',
  quantity: 50,
  place: {
    kind: 'unknown',
    locationId: null,
    name: null,
    solarSystemId: null,
    solarSystemName: null,
    unknownReason: 'market_not_chosen',
  },
  here: null,
  access: null,
}

const SOURCE_TRITANIUM_AT_JITA_AWAY: PlacedAction = {
  id: `${ISHTAR_PROJECT_ID}:source_material:line-tritanium`,
  projectId: ISHTAR_PROJECT_ID,
  projectName: 'Ishtar batch',
  code: 'source_material',
  bomLineId: 'line-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  quantity: 400,
  place: {
    kind: 'station',
    locationId: 60_003_760,
    name: 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
    solarSystemId: 30_000_142,
    solarSystemName: 'Jita',
    unknownReason: null,
  },
  here: false,
  access: null,
}

const REVIEW_JOIN_REQUESTS_ANYWHERE: PlacedAction = {
  id: `${ISHTAR_PROJECT_ID}:review_join_requests:-`,
  projectId: ISHTAR_PROJECT_ID,
  projectName: 'Ishtar batch',
  code: 'review_join_requests',
  bomLineId: null,
  typeId: null,
  typeName: null,
  quantity: 2,
  place: {
    kind: 'anywhere',
    locationId: null,
    name: null,
    solarSystemId: null,
    solarSystemName: null,
    unknownReason: null,
  },
  here: null,
  access: null,
}

const PLACED_ACTIONS = [
  LAUNCH_RAM_AT_AZBEL,
  SOURCE_TRITANIUM_AT_JITA_AWAY,
  SOURCE_ZYDRINE_UNKNOWN_PLACE,
  REVIEW_JOIN_REQUESTS_ANYWHERE,
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group place actions store', () => {
  it('reads the actions of every project while loading and keeps the unknown position as null', async () => {
    const pending = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValue(pending.promise)
    const store = useGroupPlaceActionsStore()

    void store.fetchAll()
    const whileLoading = { loading: store.loading, actions: store.actions }
    pending.resolve(PLACED_ACTIONS)
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith('/industry/place-actions')
    expect(whileLoading).toEqual({ loading: true, actions: [] })
    expect({ loading: store.loading, error: store.error, actions: store.actions }).toEqual({
      loading: false,
      error: null,
      actions: PLACED_ACTIONS,
    })
    expect(store.actions.map(action => action.here)).toStrictEqual([true, false, null, null])
  })

  it('reads the actions of one project from its own path', async () => {
    vi.mocked(apiRequest).mockResolvedValue([LAUNCH_RAM_AT_AZBEL])
    const store = useGroupPlaceActionsStore()

    await store.fetchOfProject(ISHTAR_PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(`/industry/projects/${ISHTAR_PROJECT_ID}/place-actions`)
    expect({ loading: store.loading, error: store.error, actions: store.actions }).toEqual({
      loading: false,
      error: null,
      actions: [LAUNCH_RAM_AT_AZBEL],
    })
  })

  it('replaces the actions of the previous read with the ones of the next read', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce(PLACED_ACTIONS)
    vi.mocked(apiRequest).mockResolvedValueOnce([SOURCE_ZYDRINE_UNKNOWN_PLACE])
    const store = useGroupPlaceActionsStore()

    await store.fetchAll()
    await store.fetchOfProject(ISHTAR_PROJECT_ID)

    expect(store.actions).toEqual([SOURCE_ZYDRINE_UNKNOWN_PLACE])
  })

  it('exposes the API error instead of empty actions when the cross-project request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Place actions unavailable'))
    const store = useGroupPlaceActionsStore()

    await store.fetchAll()

    expect({ loading: store.loading, error: store.error, actions: store.actions }).toEqual({
      loading: false,
      error: 'Place actions unavailable',
      actions: [],
    })
  })

  it('exposes the API error when the request of one project fails and clears it on the next success', async () => {
    vi.mocked(apiRequest).mockRejectedValueOnce(new Error('You do not have access to this project'))
    vi.mocked(apiRequest).mockResolvedValueOnce([REVIEW_JOIN_REQUESTS_ANYWHERE])
    const store = useGroupPlaceActionsStore()

    await store.fetchOfProject(ISHTAR_PROJECT_ID)
    const afterFailure = { error: store.error, actions: store.actions }
    await store.fetchOfProject(ISHTAR_PROJECT_ID)

    expect(afterFailure).toEqual({ error: 'You do not have access to this project', actions: [] })
    expect({ error: store.error, actions: store.actions }).toEqual({ error: null, actions: [REVIEW_JOIN_REQUESTS_ANYWHERE] })
  })
})
