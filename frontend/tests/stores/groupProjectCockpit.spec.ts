import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { ProjectCockpit } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-nyx-batch'
const COCKPIT_URL = `/industry/projects/${PROJECT_ID}/cockpit`

const NYX_COCKPIT: ProjectCockpit = {
  projectId: PROJECT_ID,
  status: 'in_progress',
  phases: [
    { key: 'prepare', state: 'done', counter: { done: 1, total: 1 }, blockedReason: null },
    { key: 'supply', state: 'blocked', counter: { done: 3, total: 5 }, blockedReason: { code: 'materials_missing', count: 2 } },
    { key: 'produce', state: 'upcoming', counter: { done: 0, total: 6 }, blockedReason: null },
    { key: 'sell', state: 'upcoming', counter: { done: 0, total: 2 }, blockedReason: null },
    { key: 'close', state: 'upcoming', counter: { done: 0, total: 1 }, blockedReason: null },
  ],
  nextAction: { code: 'source_material', blocked: true, tab: 'materials', targetStatus: null, bomLineId: 'line-pau', typeId: 17_459, typeName: 'Particle Accelerator Unit', quantity: 50 },
  priceAlert: null,
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('group project store cockpit', () => {
  it('loads the cockpit of the project from its cockpit route', async () => {
    vi.mocked(apiRequest).mockImplementation((async (url: string) => (url === COCKPIT_URL ? NYX_COCKPIT : [])) as never)
    const store = useGroupProjectStore()

    await store.fetchCockpit(PROJECT_ID)

    expect({ requested: vi.mocked(apiRequest).mock.calls.map(([url]) => url), cockpit: store.cockpit }).toEqual({
      requested: [COCKPIT_URL],
      cockpit: NYX_COCKPIT,
    })
  })

  it('keeps no cockpit and sets a dedicated error when the cockpit cannot be loaded', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Access denied'))
    const store = useGroupProjectStore()

    await store.fetchCockpit(PROJECT_ID)

    expect({ cockpit: store.cockpit, cockpitError: store.cockpitError, error: store.error }).toEqual({
      cockpit: null,
      cockpitError: 'Access denied',
      error: null,
    })
  })
})
