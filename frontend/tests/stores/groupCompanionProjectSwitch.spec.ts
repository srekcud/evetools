import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useGroupCompanionStore } from '@/stores/group-industry/companion'
import type { GroupProject } from '@/stores/group-industry/types'
import { bomLine, companionProject } from '../components/group-industry/companionFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECTS_PATH = '/industry/projects'
const ISHTAR_ID = 'project-ishtar'
const HAMMERHEAD_ID = 'project-hammerhead'
const DONE_ID = 'project-done'
const BOM_NAME = 'Hammerhead line'

let servedProjects: GroupProject[]
let failingPaths: Record<string, string>

function projectOf(id: string, name: string, overrides: Partial<GroupProject> = {}): GroupProject {
  return companionProject({ id, name, ...overrides })
}

function callsTo(endpoint: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(([calledEndpoint]) => calledEndpoint === endpoint).length
}

beforeEach(() => {
  setActivePinia(createPinia())
  servedProjects = [
    projectOf(ISHTAR_ID, 'Ishtar batch'),
    projectOf(HAMMERHEAD_ID, 'Hammerhead batch', { status: 'published' }),
    projectOf(DONE_ID, 'Old batch', { status: 'completed' }),
  ]
  failingPaths = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const failure = failingPaths[endpoint]
    if (failure !== undefined) throw new Error(failure)
    if (endpoint === PROJECTS_PATH) return servedProjects
    const served = servedProjects.find(project => endpoint === `${PROJECTS_PATH}/${project.id}`)
    if (served !== undefined) return served
    if (endpoint === `${PROJECTS_PATH}/${HAMMERHEAD_ID}/bom`) return [bomLine({ id: 'bom-hammerhead', typeId: 2_454, typeName: BOM_NAME })]
    if (endpoint.endsWith('/chain')) return { id: ISHTAR_ID, nodes: [] }
    if (endpoint.endsWith('/cockpit')) return { priceAlert: null, undercutOrders: [] }
    return []
  })
})

describe('useGroupCompanionStore project switch', () => {
  it('offers the projects of the user that are not completed, the opened one included', async () => {
    const store = useGroupCompanionStore()

    await store.open(ISHTAR_ID)

    expect(store.projectChoices.map(choice => [choice.id, choice.name])).toEqual([
      [ISHTAR_ID, 'Ishtar batch'],
      [HAMMERHEAD_ID, 'Hammerhead batch'],
    ])
  })

  it('names a project without a name from its items', async () => {
    servedProjects = [projectOf(ISHTAR_ID, 'Ishtar batch'), projectOf(HAMMERHEAD_ID, '', { name: null })]
    const store = useGroupCompanionStore()

    await store.open(ISHTAR_ID)

    expect(store.projectChoices.map(choice => choice.name)).toEqual(['Ishtar batch', '2x Ishtar'])
  })

  it('offers nothing and shows the failure when the list of projects cannot be read', async () => {
    failingPaths[PROJECTS_PATH] = 'Failed to fetch projects'
    const store = useGroupCompanionStore()

    await store.open(ISHTAR_ID)

    expect({ choices: store.projectChoices, error: store.error }).toEqual({ choices: [], error: 'Failed to fetch projects' })
  })

  it('opens the other project in place and drops what was read of the first one', async () => {
    const store = useGroupCompanionStore()
    await store.open(ISHTAR_ID)

    await store.switchProject(HAMMERHEAD_ID)

    expect({
      projectId: store.projectId,
      project: store.project?.id,
      bom: store.bomItems.map(item => item.typeName),
      choices: store.projectChoices.length,
    }).toEqual({ projectId: HAMMERHEAD_ID, project: HAMMERHEAD_ID, bom: [BOM_NAME], choices: 2 })
  })

  it('does not read the project again when it is already the opened one', async () => {
    const store = useGroupCompanionStore()
    await store.open(ISHTAR_ID)
    const before = callsTo(`${PROJECTS_PATH}/${ISHTAR_ID}`)

    await store.switchProject(ISHTAR_ID)

    expect(callsTo(`${PROJECTS_PATH}/${ISHTAR_ID}`)).toBe(before)
  })

  it('ignores a switch to a project that is not offered', async () => {
    const store = useGroupCompanionStore()
    await store.open(ISHTAR_ID)

    await store.switchProject(DONE_ID)

    expect(store.projectId).toBe(ISHTAR_ID)
  })

  it('reads the list of projects again after a reconnection', async () => {
    const store = useGroupCompanionStore()
    await store.open(ISHTAR_ID)
    servedProjects = [...servedProjects, projectOf('project-new', 'New batch')]

    await store.handleEvents(['reconnected'])

    expect(store.projectChoices.map(choice => choice.id)).toEqual([ISHTAR_ID, HAMMERHEAD_ID, 'project-new'])
  })

  it('keeps no choices once closed', async () => {
    const store = useGroupCompanionStore()
    await store.open(ISHTAR_ID)

    store.close()

    expect(store.projectChoices).toEqual([])
  })
})
