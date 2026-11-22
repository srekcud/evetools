import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import { companionProject, COMPANION_PROJECT_ID } from '../components/group-industry/companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_PATH = `/industry/projects/${COMPANION_PROJECT_ID}`
const MEMBERS_PATH = `${PROJECT_PATH}/members`
const UNASSIGNED_JOBS_PATH = '/industry/unassigned-jobs'

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

function loadsOf(paths: string[]): number[] {
  return paths.map(callsTo)
}

const LOADED_PATHS = [PROJECT_PATH, `${PROJECT_PATH}/bom`, `${PROJECT_PATH}/chain`, `${PROJECT_PATH}/cockpit`, MEMBERS_PATH]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === PROJECT_PATH) return companionProject({ myRole: 'owner' })
    if (endpoint === `${PROJECT_PATH}/chain`) return { id: COMPANION_PROJECT_ID, nodes: [] }
    if (endpoint === `${PROJECT_PATH}/cockpit`) return { priceAlert: null, undercutOrders: [] }
    return []
  })
})

describe('useGroupCompanionStore.handleEvents', () => {
  it('reloads the project, the BOM, the chain and the cockpit once for a batch of events, and the members once when one of them concerns a member', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const loadsAtOpen = loadsOf(LOADED_PATHS)

    await store.handleEvents(['sale_recorded', 'member_joined', 'sale_updated'])

    expect(loadsOf(LOADED_PATHS).map((count, index) => count - loadsAtOpen[index])).toEqual([1, 1, 1, 1, 1])
  })

  it('leaves the members alone when no event of the batch concerns a member', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const membersAtOpen = callsTo(MEMBERS_PATH)

    await store.handleEvents(['sale_recorded', 'line_reserved'])

    expect(callsTo(MEMBERS_PATH)).toBe(membersAtOpen)
  })

  it('reloads the unassigned jobs once for a batch of events, so an assignment made from the page updates the companion', async () => {
    const store = useGroupCompanionStore()
    await store.open(COMPANION_PROJECT_ID)
    const loadsAtOpen = callsTo(UNASSIGNED_JOBS_PATH)

    await store.handleEvents(['sale_recorded', 'line_reserved', 'sale_updated'])

    expect(callsTo(UNASSIGNED_JOBS_PATH) - loadsAtOpen).toBe(1)
  })
})
