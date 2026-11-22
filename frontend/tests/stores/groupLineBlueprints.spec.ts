import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupLineBlueprintsStore } from '@/stores/group-industry/lineBlueprints'
import type { AttachLineBlueprintInput, LineBlueprintCandidate, ProjectLineBlueprint } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_PROJECT_ID = 'project-ishtar-blueprints'
const ISHTAR_BLUEPRINT_TYPE_ID = 12_006
const ISHTAR_LINE_ID = 'line-ishtar'
const STEP_STATION_ID = 60_003_760
const OTHER_STATION_ID = 60_008_494

const BLUEPRINT_NOT_SYNCED: ProjectLineBlueprint = {
  id: 'line-blueprint-lost',
  bomLineId: ISHTAR_LINE_ID,
  itemId: 1_296_000_000_001,
  typeId: ISHTAR_BLUEPRINT_TYPE_ID,
  typeName: 'Ishtar Blueprint',
  kind: 'bpc',
  runs: null,
  materialEfficiency: null,
  timeEfficiency: null,
  attachedBy: null,
  attachedAt: '2026-11-22T09:00:00+00:00',
  stepPlace: { kind: 'station', locationId: STEP_STATION_ID, name: 'Alpha', solarSystemId: 30_000_144, solarSystemName: 'Perimeter', unknownReason: null },
  blueprintPlace: { locationId: null, name: null, solarSystemName: null },
  state: 'unknown',
  unknownReason: 'blueprint_not_synced',
  transferToMake: null,
}

const ELSEWHERE_CANDIDATE: LineBlueprintCandidate = {
  bomLineId: ISHTAR_LINE_ID,
  itemId: 1_296_000_000_003,
  typeId: ISHTAR_BLUEPRINT_TYPE_ID,
  typeName: 'Ishtar Blueprint',
  kind: 'bpc',
  runs: 50,
  materialEfficiency: 8,
  timeEfficiency: 16,
  state: 'elsewhere',
  unknownReason: null,
  transferToMake: {
    typeId: ISHTAR_BLUEPRINT_TYPE_ID,
    typeName: 'Ishtar Blueprint',
    itemId: 1_296_000_000_003,
    quantity: 1,
    from: { locationId: OTHER_STATION_ID, name: 'Elsewhere Station', solarSystemName: 'Amarr' },
    to: { locationId: STEP_STATION_ID, name: 'Alpha', solarSystemId: 30_000_144, solarSystemName: 'Perimeter' },
  },
}

const ATTACHMENT: AttachLineBlueprintInput = { bomLineId: ISHTAR_LINE_ID, itemId: 1_296_000_000_003 }

function lineBlueprintsPath(projectId: string): string {
  return `/industry/projects/${projectId}/line-blueprints`
}

function candidatesPath(projectId: string): string {
  return `/industry/projects/${projectId}/line-blueprint-candidates`
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group line blueprints store', () => {
  it('reads the blueprints of the project and keeps the unknown state, the reason and the null values', async () => {
    vi.mocked(apiRequest).mockResolvedValue([BLUEPRINT_NOT_SYNCED])
    const store = useGroupLineBlueprintsStore()

    await store.fetchLineBlueprints(ISHTAR_PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(lineBlueprintsPath(ISHTAR_PROJECT_ID))
    expect({ loading: store.loading, error: store.error, blueprints: store.blueprints }).toEqual({
      loading: false,
      error: null,
      blueprints: [BLUEPRINT_NOT_SYNCED],
    })
    expect(store.blueprints[0]).toHaveProperty('runs', null)
    expect(store.blueprints[0]).toHaveProperty('transferToMake', null)
    expect(store.blueprints[0]).toHaveProperty('state', 'unknown')
  })

  it('reads the candidates of the project with the transfer to make of a blueprint that is elsewhere', async () => {
    vi.mocked(apiRequest).mockResolvedValue([ELSEWHERE_CANDIDATE])
    const store = useGroupLineBlueprintsStore()

    await store.fetchLineBlueprintCandidates(ISHTAR_PROJECT_ID)

    expect(apiRequest).toHaveBeenCalledExactlyOnceWith(candidatesPath(ISHTAR_PROJECT_ID))
    expect(store.candidates).toEqual([ELSEWHERE_CANDIDATE])
    expect(store.candidates[0]?.transferToMake?.from.locationId).toBe(OTHER_STATION_ID)
  })

  it('attaches a blueprint with a POST then reloads the blueprints of the project', async () => {
    vi.mocked(apiRequest).mockImplementation(async (path, options) => {
      if (options?.method === 'POST') return BLUEPRINT_NOT_SYNCED
      return path === lineBlueprintsPath(ISHTAR_PROJECT_ID) ? [BLUEPRINT_NOT_SYNCED] : []
    })
    const store = useGroupLineBlueprintsStore()

    await store.attachLineBlueprint(ISHTAR_PROJECT_ID, ATTACHMENT)

    expect(vi.mocked(apiRequest).mock.calls[0]).toEqual([lineBlueprintsPath(ISHTAR_PROJECT_ID), { method: 'POST', body: JSON.stringify(ATTACHMENT) }])
    expect(vi.mocked(apiRequest).mock.calls).toContainEqual([lineBlueprintsPath(ISHTAR_PROJECT_ID)])
    expect(store.blueprints).toEqual([BLUEPRINT_NOT_SYNCED])
  })

  it('detaches a blueprint with a DELETE then reloads the blueprints of the project', async () => {
    vi.mocked(apiRequest).mockResolvedValue([])
    const store = useGroupLineBlueprintsStore()

    await store.detachLineBlueprint(ISHTAR_PROJECT_ID, 'line-blueprint-lost')

    expect(vi.mocked(apiRequest).mock.calls[0]).toEqual([`${lineBlueprintsPath(ISHTAR_PROJECT_ID)}/line-blueprint-lost`, { method: 'DELETE' }])
    expect(vi.mocked(apiRequest).mock.calls).toContainEqual([lineBlueprintsPath(ISHTAR_PROJECT_ID)])
    expect(store.blueprints).toEqual([])
  })

  it('reports the error of a failed read and keeps no blueprint', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Forbidden'))
    const store = useGroupLineBlueprintsStore()

    await store.fetchLineBlueprints(ISHTAR_PROJECT_ID)
    await flushPromises()

    expect({ loading: store.loading, error: store.error, blueprints: store.blueprints }).toEqual({
      loading: false,
      error: 'Forbidden',
      blueprints: [],
    })
  })
})
