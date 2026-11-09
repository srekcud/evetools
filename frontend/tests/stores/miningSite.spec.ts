import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningSiteStore } from '@/stores/miningSite'
import { ALDRANETTE, CLOSE_PATH, OPERATION_ID, SCANS_PATH, SITES_PATH, SITE_ID, SITE_PATH, VELDSPAR, SCORDITE, at, presentType, scanOf, siteAfterThreeScans, siteOf, veldsparAndScorditeScans } from '../views/MiningSite/miningSiteFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ANOMALY_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e50'

async function outcomeOf<T>(call: Promise<T>): Promise<{ value: T } | { rejection: unknown }> {
  const [settled] = await Promise.allSettled([call])
  return settled.status === 'fulfilled' ? { value: settled.value } : { rejection: String(settled.reason) }
}

function postOf(path: string) {
  return vi.mocked(apiRequest).mock.calls.find(call => call[0] === path && call[1]?.method === 'POST')
}

function postedBody(path: string): unknown {
  return JSON.parse(String(postOf(path)?.[1]?.body))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('mining site store', () => {
  it('loads my sites, one site and its scans', async () => {
    const belt = siteAfterThreeScans()
    const anomaly = siteOf({ id: ANOMALY_ID, label: 'Anomalie Spodumain', solarSystemId: null, solarSystemName: null })
    const scans = veldsparAndScorditeScans()
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === SITES_PATH) return [anomaly, belt] as never
      if (endpoint === SITE_PATH) return belt as never
      if (endpoint === SCANS_PATH) return scans as never
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const store = useMiningSiteStore()

    await store.fetchSites()
    await store.fetchSite(SITE_ID)
    await store.fetchScans(SITE_ID)

    expect({
      requests: vi.mocked(apiRequest).mock.calls.map(call => call[0]),
      sites: store.sites,
      currentSite: store.currentSite,
      scans: store.scans,
      error: store.error,
    }).toEqual({
      requests: [SITES_PATH, SITE_PATH, SCANS_PATH],
      sites: [anomaly, belt],
      currentSite: belt,
      scans,
      error: null,
    })
  })

  it('creates a site, records a scan as a list of lines and closes the site', async () => {
    const created = siteOf({ id: ANOMALY_ID, label: 'Anomalie Spodumain 2' })
    const existing = siteAfterThreeScans()
    const recorded = scanOf(4, at('20:30:00'), [presentType(VELDSPAR, 12_000, { volumeM3: 1_200 }), presentType(SCORDITE, 4_000, { volumeM3: 600 })])
    const closed = { ...existing, status: 'closed' as const }
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
      if (endpoint === SITES_PATH) return (options?.method === 'POST' ? created : [existing]) as never
      if (endpoint === SCANS_PATH) return recorded as never
      if (endpoint === CLOSE_PATH) return closed as never
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const store = useMiningSiteStore()
    store.sites = [existing]
    store.scans = veldsparAndScorditeScans()
    store.currentSite = existing

    const createdSite = await outcomeOf(store.create({ label: 'Anomalie Spodumain 2', solarSystemId: ALDRANETTE }))
    const scan = await outcomeOf(store.recordScan(SITE_ID, [{ typeId: VELDSPAR, units: 12_000 }, { typeId: SCORDITE, units: 4_000 }]))
    await outcomeOf(store.close(SITE_ID))

    expect({
      createdSite,
      sitesAfterCreation: store.sites.map(site => [site.id, site.status]),
      createBody: postedBody(SITES_PATH),
      scan,
      scanBody: postedBody(SCANS_PATH),
      scanNumbers: store.scans.map(storedScan => storedScan.number),
      closePosted: postOf(CLOSE_PATH) !== undefined,
      currentStatus: store.currentSite?.status,
    }).toEqual({
      createdSite: { value: created },
      sitesAfterCreation: [[ANOMALY_ID, 'active'], [SITE_ID, 'closed']],
      createBody: { label: 'Anomalie Spodumain 2', solarSystemId: ALDRANETTE },
      scan: { value: recorded },
      scanBody: { lines: [{ typeId: VELDSPAR, units: 12_000 }, { typeId: SCORDITE, units: 4_000 }] },
      scanNumbers: [1, 2, 3, 4],
      closePosted: true,
      currentStatus: 'closed',
    })
  })

  it('keeps the message of the server and rethrows when a write is refused or a site is not found', async () => {
    const refusal = new ApiError('A scan at the same instant already exists for this site', 422)
    const notFound = new ApiError('Mining site not found', 404)
    vi.mocked(apiRequest).mockRejectedValueOnce(refusal).mockRejectedValueOnce(notFound)
    const store = useMiningSiteStore()

    await expect(store.recordScan(SITE_ID, [{ typeId: VELDSPAR, units: 12_000 }])).rejects.toBe(refusal)
    const errorAfterRefusal = store.error
    await store.fetchSite(SITE_ID)

    expect({ errorAfterRefusal, errorAfterNotFound: store.error, currentSite: store.currentSite }).toEqual({
      errorAfterRefusal: 'A scan at the same instant already exists for this site',
      errorAfterNotFound: 'Mining site not found',
      currentSite: null,
    })
  })

  it('loads the sites of an operation whoever owns them and creates a site linked to the operation', async () => {
    const ownSite = siteAfterThreeScans({ operationId: OPERATION_ID })
    const colleagueSite = siteOf({ id: ANOMALY_ID, operationId: OPERATION_ID, label: 'Anomalie Spodumain', solarSystemId: null, solarSystemName: null })
    const operationSitesPath = `${SITES_PATH}?operationId=${OPERATION_ID}`
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
      if (endpoint === operationSitesPath) return [colleagueSite, ownSite] as never
      if (endpoint === SITES_PATH && options?.method === 'POST') return colleagueSite as never
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const store = useMiningSiteStore()

    await store.fetchOperationSites(OPERATION_ID)
    await store.create({ label: 'Anomalie Spodumain', solarSystemId: null, operationId: OPERATION_ID })

    expect({
      operationSites: store.operationSites,
      createBody: postedBody(SITES_PATH),
      error: store.error,
    }).toEqual({
      operationSites: [colleagueSite, ownSite],
      createBody: { label: 'Anomalie Spodumain', solarSystemId: null, operationId: OPERATION_ID },
      error: null,
    })
  })

  it('empties the sites of the operation and keeps the message of the server when they cannot be loaded', async () => {
    vi.mocked(apiRequest).mockRejectedValueOnce(new ApiError('Mining operation not found', 404))
    const store = useMiningSiteStore()
    store.operationSites = [siteAfterThreeScans({ operationId: OPERATION_ID })]

    await store.fetchOperationSites(OPERATION_ID)

    expect({ operationSites: store.operationSites, error: store.error }).toEqual({
      operationSites: [],
      error: 'Mining operation not found',
    })
  })

  it('starts the next site of an operation, then reloads the sites of the operation and returns the new site', async () => {
    const previous = siteAfterThreeScans({ operationId: OPERATION_ID, status: 'closed', closedAt: at('20:30:00'), scanCount: 3, extractedValue: 180_000 })
    const next = siteOf({ id: ANOMALY_ID, operationId: OPERATION_ID, label: 'Belt Jaspet', solarSystemId: ALDRANETTE })
    const operationSitesPath = `${SITES_PATH}?operationId=${OPERATION_ID}`
    const nextSitePath = `/mining-operations/${OPERATION_ID}/next-site`
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
      if (endpoint === nextSitePath && options?.method === 'POST') return next as never
      if (endpoint === operationSitesPath) return [next, previous] as never
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const store = useMiningSiteStore()
    store.operationSites = [siteAfterThreeScans({ operationId: OPERATION_ID })]

    const outcome = await outcomeOf(store.startNextSite(OPERATION_ID, { label: 'Belt Jaspet', solarSystemId: ALDRANETTE }))

    expect({
      outcome,
      requests: vi.mocked(apiRequest).mock.calls.map(call => call[0]),
      body: postedBody(nextSitePath),
      operationSites: store.operationSites,
      error: store.error,
    }).toEqual({
      outcome: { value: next },
      requests: [nextSitePath, operationSitesPath],
      body: { label: 'Belt Jaspet', solarSystemId: ALDRANETTE },
      operationSites: [next, previous],
      error: null,
    })
  })

  it('keeps the message of the server and rethrows when the next site is refused, without reloading nor changing the sites of the operation', async () => {
    const forbidden = new ApiError('Only the site owner or an operation admin can close this site', 403)
    const archived = new ApiError('This operation is archived', 400)
    const ownSites = [siteAfterThreeScans({ operationId: OPERATION_ID })]
    vi.mocked(apiRequest).mockRejectedValueOnce(forbidden).mockRejectedValueOnce(archived)
    const store = useMiningSiteStore()
    store.operationSites = ownSites

    const refused = await outcomeOf(store.startNextSite(OPERATION_ID, { label: 'Belt Jaspet', solarSystemId: null }))
    const errorAfterForbidden = store.error
    const archivedRefusal = await outcomeOf(store.startNextSite(OPERATION_ID, { label: 'Belt Jaspet', solarSystemId: null }))

    expect({
      refused,
      errorAfterForbidden,
      archivedRefusal,
      errorAfterArchived: store.error,
      requests: vi.mocked(apiRequest).mock.calls.map(call => call[0]),
      operationSites: store.operationSites,
    }).toEqual({
      refused: { rejection: String(forbidden) },
      errorAfterForbidden: 'Only the site owner or an operation admin can close this site',
      archivedRefusal: { rejection: String(archived) },
      errorAfterArchived: 'This operation is archived',
      requests: [`/mining-operations/${OPERATION_ID}/next-site`, `/mining-operations/${OPERATION_ID}/next-site`],
      operationSites: ownSites,
    })
  })
})
