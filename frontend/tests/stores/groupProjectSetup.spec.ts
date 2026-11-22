import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { useProjectSetupStore } from '@/stores/group-industry/projectSetup'
import { bomLine } from '../views/GroupIndustry/projectCockpitFixtures'
import { deferred } from '../deferred'
import {
  BASE_TYPE_ID,
  projectWorkStations,
  REACTION_TYPE_ID,
  requestsOf,
  sourcingDecisions,
} from '../projectSetupFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-setup-batch'
const PROJECT_PATH = `/industry/projects/${PROJECT_ID}`
const SOURCING_PATH = `${PROJECT_PATH}/sourcing-decisions`
const BOM_PATH = `${PROJECT_PATH}/bom`
const REACTION_LINE_ID = 'bom-line-reaction-purchase'
const BASE_LINE_ID = 'bom-line-base-purchase'

function bomOfTheProject() {
  return [
    bomLine({ id: 'bom-line-reaction-job', typeId: REACTION_TYPE_ID, typeName: 'Fernite Carbide', isJob: true }),
    bomLine({ id: REACTION_LINE_ID, typeId: REACTION_TYPE_ID, typeName: 'Fernite Carbide' }),
    bomLine({ id: BASE_LINE_ID, typeId: BASE_TYPE_ID, typeName: 'Tritanium' }),
  ]
}

function answerWithBom(): void {
  vi.mocked(apiRequest).mockImplementation((async (path: string, options?: { method?: string }) => {
    if (path === BOM_PATH && options?.method === undefined) return bomOfTheProject()
    return {}
  }) as never)
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('project setup store reads', () => {
  it('reads the work stations and the sourcing decisions with the buy and build ids, keeps the first total as baseline and the answer of the latest request', async () => {
    vi.mocked(apiRequest)
      .mockResolvedValueOnce(projectWorkStations())
      .mockResolvedValueOnce(sourcingDecisions(1000))
      .mockResolvedValueOnce(sourcingDecisions(950))
    const store = useProjectSetupStore()

    await store.fetchWorkStations(PROJECT_ID)
    await store.fetchSourcingDecisions(PROJECT_ID, { buy: [], build: [] })
    await store.fetchSourcingDecisions(PROJECT_ID, { buy: [1, 2], build: [3] })

    expect(requestsOf(vi.mocked(apiRequest)).map(request => request.path)).toEqual([
      `${PROJECT_PATH}/work-stations`,
      SOURCING_PATH,
      `${SOURCING_PATH}?buy=1,2&build=3`,
    ])
    expect({ workStations: store.workStations, total: store.decisions?.totals.total, baselineTotal: store.baselineTotal }).toEqual({
      workStations: projectWorkStations(),
      total: 950,
      baselineTotal: 1000,
    })

    const older = deferred<unknown>()
    const latest = deferred<unknown>()
    vi.mocked(apiRequest).mockReturnValueOnce(older.promise).mockReturnValueOnce(latest.promise)
    void store.fetchSourcingDecisions(PROJECT_ID, { buy: [1], build: [] })
    void store.fetchSourcingDecisions(PROJECT_ID, { buy: [2], build: [] })
    latest.resolve(sourcingDecisions(800))
    await flushPromises()
    older.resolve(sourcingDecisions(700))
    await flushPromises()

    expect(store.decisions?.totals.total).toBe(800)
  })
})

describe('project setup store save', () => {
  it('writes the stations, then the blacklist, then the market of each bought line found in the bill of materials', async () => {
    answerWithBom()
    const store = useProjectSetupStore()

    const saved = await store.saveSetup(PROJECT_ID, {
      workStations: { reaction: '1002' },
      blacklistTypeIds: [REACTION_TYPE_ID],
      markets: [
        { typeId: REACTION_TYPE_ID, market: 'structure' },
        { typeId: BASE_TYPE_ID, market: 'jita' },
      ],
    })

    expect(requestsOf(vi.mocked(apiRequest))).toEqual([
      { method: 'PATCH', path: PROJECT_PATH, body: { workStations: { reaction: '1002' } } },
      { method: 'PATCH', path: PROJECT_PATH, body: { blacklistTypeIds: [REACTION_TYPE_ID] } },
      { method: 'GET', path: BOM_PATH, body: undefined },
      { method: 'POST', path: `${BOM_PATH}/${REACTION_LINE_ID}/sourcing-market`, body: { market: 'structure' } },
      { method: 'POST', path: `${BOM_PATH}/${BASE_LINE_ID}/sourcing-market`, body: { market: 'jita' } },
    ])
    expect({ saved, saveError: store.saveError, saving: store.saving }).toEqual({ saved: true, saveError: null, saving: false })
  })

  it('skips the blacklist when it is null and stops with an error at a line missing from the bill of materials', async () => {
    answerWithBom()
    const store = useProjectSetupStore()
    const MISSING_TYPE_ID = 99

    const saved = await store.saveSetup(PROJECT_ID, {
      workStations: {},
      blacklistTypeIds: null,
      markets: [
        { typeId: REACTION_TYPE_ID, market: 'structure' },
        { typeId: MISSING_TYPE_ID, market: 'jita' },
        { typeId: BASE_TYPE_ID, market: 'jita' },
      ],
    })

    expect(requestsOf(vi.mocked(apiRequest)).map(request => `${request.method} ${request.path}`)).toEqual([
      `PATCH ${PROJECT_PATH}`,
      `GET ${BOM_PATH}`,
      `POST ${BOM_PATH}/${REACTION_LINE_ID}/sourcing-market`,
    ])
    expect({ saved, hasError: store.saveError !== null && store.saveError !== '', saving: store.saving }).toEqual({ saved: false, hasError: true, saving: false })
  })
})
