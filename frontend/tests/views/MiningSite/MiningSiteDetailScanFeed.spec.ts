import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { ApiError, apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import MiningSiteDetail from '@/views/MiningSite/MiningSiteDetail.vue'
import {
  OPERATION_ID,
  ORE_VALUES_PATH,
  OTHER_SITE_ID,
  SCANS_PATH,
  SITE_ID,
  SITE_PATH,
  VELDSPAR,
  alphaBravoCharlieScans,
  at,
  hasAmberText,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteAfterCharlieScan,
} from './miningSiteFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: SITE_ID }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const events = reactive<Record<string, MiningOperationEvent | null>>({})
const served: { site: MiningSite; scans: MiningSiteScan[]; failure: ApiError | null } = { site: siteAfterCharlieScan(), scans: [], failure: null }

function serve(site: MiningSite, scans: MiningSiteScan[]): void {
  served.site = site
  served.scans = scans
  served.failure = null
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

async function mountDetail() {
  const watching = vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
  watching.mockClear()
  const i18n = i18nFor('fr')
  const wrapper = mount(MiningSiteDetail, {
    global: {
      plugins: [i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  vi.mocked(apiRequest).mockClear()
  return { wrapper, t: i18n.global.t, watchedOperationIds: () => [...new Set(watching.mock.calls.map(call => call[0]))] }
}

async function receive(action: string, data: Record<string, unknown> | null): Promise<void> {
  events[OPERATION_ID] = { action, data, timestamp: at('20:31:00') }
  await nextTick()
  await flushPromises()
}

function setNow(clock: string): void {
  vi.setSystemTime(new Date(at(clock)))
}

function normalizedTextOf(wrapper: VueWrapper, testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text().replace(/\s+/g, ' ').trim() : null
}

function feedNumbers(wrapper: VueWrapper): (string | undefined)[] {
  return wrapper.findAll('[data-testid="scan-feed-row"]').map(row => row.attributes('data-scan-number'))
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  setNow('20:24:30')
  setActivePinia(createPinia())
  localStorage.clear()
  Object.keys(events).forEach(operationId => delete events[operationId])
  serve(siteAfterCharlieScan(), alphaBravoCharlieScans())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
    if (served.failure !== null) throw served.failure
    if (endpoint === SITE_PATH) return served.site as never
    if (endpoint === SCANS_PATH) return served.scans as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningSiteDetail, shared scan feed', () => {
  it('shows the scan feed of an operation site with its shared subtitle and the one of a private site without it, naming an unknown author', async () => {
    const operationSite = await mountDetail()
    const operationFeed = { numbers: feedNumbers(operationSite.wrapper), shared: operationSite.wrapper.text().includes(operationSite.t('miningSite.feed.shared')) }
    operationSite.wrapper.unmount()
    const withUnknownAuthor = alphaBravoCharlieScans().map(scan => (scan.number === 2 ? { ...scan, authorName: null } : scan))
    serve(siteAfterCharlieScan({ operationId: null }), withUnknownAuthor)

    const privateSite = await mountDetail()

    const unknownRow = privateSite.wrapper.find('[data-testid="scan-feed-row"][data-scan-number="2"]')
    expect({
      operationFeed,
      privateFeed: { numbers: feedNumbers(privateSite.wrapper), shared: privateSite.wrapper.text().includes(privateSite.t('miningSite.feed.shared')) },
      unknownAuthorInAmber: unknownRow.exists() && hasAmberText(unknownRow, 'auteur inconnu'),
    }).toEqual({
      operationFeed: { numbers: ['3', '2', '1'], shared: true },
      privateFeed: { numbers: ['3', '2', '1'], shared: false },
      unknownAuthorInAmber: true,
    })
  })

  it('shows the last scan with its author and its age on an active site, saying just now under a minute, and nothing on a closed site', async () => {
    const atFourMinutes = await mountDetail()
    const fourMinutes = normalizedTextOf(atFourMinutes.wrapper, 'site-last-scan')
    atFourMinutes.wrapper.unmount()
    setNow('20:20:20')
    const justNow = await mountDetail()
    const justNowText = normalizedTextOf(justNow.wrapper, 'site-last-scan')
    justNow.wrapper.unmount()
    setNow('21:30:00')
    serve(siteAfterCharlieScan({ status: 'closed' }), alphaBravoCharlieScans())

    const closed = await mountDetail()

    expect({
      fourMinutes,
      justNow: justNowText,
      closedLastScan: normalizedTextOf(closed.wrapper, 'site-last-scan'),
      closedReminder: closed.wrapper.find('[data-testid="site-rescan"]').exists(),
      closedFeed: feedNumbers(closed.wrapper),
    }).toEqual({
      fourMinutes: 'Dernier scan #3 par Charlie, il y a 4 min',
      justNow: expect.stringContaining('à l\'instant'),
      closedLastScan: null,
      closedReminder: false,
      closedFeed: ['3', '2', '1'],
    })
    expect(justNowText).not.toContain('il y a')
  })

  it('reloads the scans and the site on a scan recorded on the displayed site, which resets the age and removes the re-scan reminder', async () => {
    setNow('20:31:00')
    const { wrapper } = await mountDetail()
    const before = { lastScan: normalizedTextOf(wrapper, 'site-last-scan'), reminder: wrapper.find('[data-testid="site-rescan"]').exists() }
    serve(
      siteAfterCharlieScan({ lastScanAt: at('20:31:00'), lastScanNumber: 4, lastScanAuthorName: 'Dana' }),
      [...alphaBravoCharlieScans(), scanOf(4, at('20:31:00'), [presentType(VELDSPAR, 12_000, { volumeM3: 1_200 })], { authorName: 'Dana' })],
    )

    await receive('site_scan_recorded', { siteId: SITE_ID })

    expect({
      before,
      siteReloads: callsTo(SITE_PATH),
      scansReloads: callsTo(SCANS_PATH),
      feed: feedNumbers(wrapper),
      lastScan: normalizedTextOf(wrapper, 'site-last-scan'),
      reminder: wrapper.find('[data-testid="site-rescan"]').exists(),
    }).toEqual({
      before: { lastScan: 'Dernier scan #3 par Charlie, il y a 11 min', reminder: true },
      siteReloads: 1,
      scansReloads: 1,
      feed: ['4', '3', '2', '1'],
      lastScan: expect.stringContaining('Dana'),
      reminder: false,
    })
  })

  it('ignores the events of another site, of another action or without a site identifier, and watches nothing on a private site', async () => {
    const operationSite = await mountDetail()
    const watchedByOperationSite = operationSite.watchedOperationIds()

    await receive('site_scan_recorded', { siteId: SITE_ID })
    const afterMatching = callsTo(SITE_PATH) + callsTo(SCANS_PATH)
    await receive('site_scan_recorded', { siteId: OTHER_SITE_ID })
    await receive('member_joined', { siteId: SITE_ID })
    await receive('site_scan_recorded', {})
    await receive('site_scan_recorded', { siteId: 42 })
    await receive('site_scan_recorded', null)
    const afterIgnored = callsTo(SITE_PATH) + callsTo(SCANS_PATH)
    operationSite.wrapper.unmount()
    serve(siteAfterCharlieScan({ operationId: null }), alphaBravoCharlieScans())
    const privateSite = await mountDetail()

    await receive('site_scan_recorded', { siteId: SITE_ID })

    expect({
      watchedByOperationSite,
      afterMatching,
      afterIgnored,
      watchedByPrivateSite: privateSite.watchedOperationIds(),
      privateSiteReloads: callsTo(SITE_PATH) + callsTo(SCANS_PATH),
    }).toEqual({
      watchedByOperationSite: [OPERATION_ID],
      afterMatching: 2,
      afterIgnored: 2,
      watchedByPrivateSite: [],
      privateSiteReloads: 0,
    })
  })

  it('shows the failure of a reload triggered by an event in the error banner, keeps the scans on screen and does not retry', async () => {
    const { wrapper } = await mountDetail()
    served.failure = new ApiError('Mining site not found', 404)

    await receive('site_scan_recorded', { siteId: SITE_ID })

    expect({
      alerts: wrapper.findAll('[role="alert"]').map(alert => alert.text()),
      feed: feedNumbers(wrapper),
      scansRequests: callsTo(SCANS_PATH),
    }).toEqual({
      alerts: [expect.stringContaining('Mining site not found')],
      feed: ['3', '2', '1'],
      scansRequests: 1,
    })
  })
})
