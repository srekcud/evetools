import { DOMWrapper, enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import MiningCompanionHost from '@/components/mining-site/MiningCompanionHost.vue'
import { useAuthStore } from '@/stores/auth'
import { useMiningCompanionStore, type CompanionTarget } from '@/stores/miningCompanion'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { useMiningSiteStore, type MiningSite, type MiningSiteScan } from '@/stores/miningSite'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'
import { OPERATION_P2, startedSpodumainOperation } from '../../views/MiningOperation/miningOperationLedgerFixtures'
import {
  OPERATION_ID,
  OTHER_SITE_ID,
  SITES_PATH,
  SITE_ID,
  VELDSPAR,
  at,
  depletedType,
  found,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteOf,
} from '../../views/MiningSite/miningSiteFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ params: {}, query: {} })) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

class FakeEventSource {
  static readonly CLOSED = 2
  static instances: FakeEventSource[] = []

  readyState = 1
  onopen: (() => void) | null = null
  onmessage: ((event: MessageEvent) => void) | null = null
  onerror: ((event: Event) => void) | null = null

  constructor(readonly url: string) {
    FakeEventSource.instances.push(this)
  }

  close(): void {
    this.readyState = FakeEventSource.CLOSED
  }
}

class FakeNotification {
  static permission = 'granted'
  static shown: Array<{ title: string; options: NotificationOptions | undefined }> = []

  constructor(title: string, options?: NotificationOptions) {
    FakeNotification.shown.push({ title, options })
  }
}

interface World {
  operation: ReturnType<typeof startedSpodumainOperation>
  sites: MiningSite[]
  siteById: Record<string, MiningSite>
  scansById: Record<string, MiningSiteScan[]>
  nextSiteFailure: Error | null
}

const USER_ID = '00000000-0000-4000-8000-000000000304'
const NEXT_SITE_PATH = `/mining-operations/${OPERATION_ID}/next-site`
const OPERATION_SITES_PATH = `${SITES_PATH}?operationId=${OPERATION_ID}`
const SCANS_PATH = `${SITES_PATH}/${SITE_ID}/scans`
const NEXT_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e60'
const UNRELATED_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e99'
const FORBIDDEN_MESSAGE = 'Only the site owner or an operation admin can close this site'
const VALID_SCAN_TEXT = 'Veldspar\t10.000\t1.000 m3\t-\t5 km'
const OPERATION_TARGET: CompanionTarget = { siteId: null, operationId: OPERATION_ID }

let world: World
let pinia: Pinia
let clipboardText = ''

function freshSite(overrides: Partial<MiningSite> = {}): MiningSite {
  return siteOf({ id: SITE_ID, operationId: OPERATION_ID, lastScanNumber: 1, ...overrides })
}

function freshScans(): MiningSiteScan[] {
  return [scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 })], { authorName: 'Aria Solberg' })]
}

function depletedScans(): MiningSiteScan[] {
  return [...freshScans(), scanOf(2, at('20:10:00'), [depletedType(VELDSPAR, at('20:00:00'), at('20:10:00'))], { authorName: 'Brann Holt' })]
}

function resetWorld(): void {
  const site = freshSite()
  world = {
    operation: startedSpodumainOperation(),
    sites: [site],
    siteById: { [SITE_ID]: site, [OTHER_SITE_ID]: freshSite({ id: OTHER_SITE_ID, label: 'Belt Other' }) },
    scansById: { [SITE_ID]: freshScans(), [OTHER_SITE_ID]: [] },
    nextSiteFailure: null,
  }
}

function answer(endpoint: string, options?: RequestInit): unknown {
  const method = options?.method ?? 'GET'
  if (method === 'POST' && endpoint === NEXT_SITE_PATH) {
    if (world.nextSiteFailure !== null) throw world.nextSiteFailure
    const started = freshSite({ id: NEXT_SITE_ID, label: JSON.parse(String(options?.body)).label, createdAt: at('20:30:00') })
    world.sites = [started, ...world.sites.map(site => ({ ...site, status: 'closed' as const }))]
    world.siteById[NEXT_SITE_ID] = started
    world.scansById[NEXT_SITE_ID] = []
    return started
  }
  if (method === 'POST' && endpoint === SCANS_PATH) {
    const recorded = scanOf(2, at('20:40:00'), [presentType(VELDSPAR, 10_000, { volumeM3: 1_000 })])
    world.scansById[SITE_ID] = [...world.scansById[SITE_ID], recorded]
    return recorded
  }
  if (endpoint === `/mining-operations/${OPERATION_ID}`) return world.operation
  if (endpoint === OPERATION_SITES_PATH) return world.sites
  if (endpoint.startsWith('/mining/ore-values')) return oreValues()
  const siteMatch = /^\/mining-sites\/([^/?]+)(\/scans)?$/.exec(endpoint)
  if (siteMatch !== null) return siteMatch[2] === undefined ? world.siteById[siteMatch[1]] : (world.scansById[siteMatch[1]] ?? [])
  throw new Error(`Unexpected request ${method} ${endpoint}`)
}

function mountHost(target: CompanionTarget) {
  const i18n = i18nFor('en')
  const companion = useMiningCompanionStore()
  companion.companionWindow = window
  companion.target = target
  const wrapper = mount(MiningCompanionHost, { global: { plugins: [pinia, i18n] } })
  return { wrapper, companion, t: i18n.global.t }
}

function screen(): DOMWrapper<Element> {
  return new DOMWrapper(document.body)
}

function textOf(testId: string): string | null {
  const element = screen().find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

function exists(testId: string): boolean {
  return screen().find(`[data-testid="${testId}"]`).exists()
}

async function settle(): Promise<void> {
  await flushPromises()
  await flushPromises()
}

async function openNextSiteForm(): Promise<void> {
  await found(screen(), '[data-testid="companion-next-site"]').trigger('click')
}

async function submitNextSite(label: string): Promise<void> {
  await found(screen(), 'input[name="label"]').setValue(label)
  await found(screen(), '[data-testid="companion-next-site-form"]').trigger('submit')
  await settle()
}

function bodiesPosted(path: string): unknown[] {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path && call[1]?.method === 'POST').map(call => JSON.parse(String(call[1]?.body)))
}

async function connectedEvents(): Promise<(message: Record<string, unknown>) => void> {
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: USER_ID,
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98_262_101,
    corporationName: 'Spodumain Corp',
  } as User
  await useSyncStore().connect()
  await vi.waitFor(() => expect(FakeEventSource.instances.length).toBeGreaterThan(0))
  const source = FakeEventSource.instances[FakeEventSource.instances.length - 1]
  source.onopen?.()
  return (message) => {
    source.onmessage?.(new MessageEvent('message', { data: JSON.stringify({ operationId: OPERATION_ID, timestamp: at('20:35:00'), ...message }) }))
  }
}

async function focusCompanionWindow(): Promise<void> {
  window.dispatchEvent(new Event('focus'))
  await settle()
}

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  resetWorld()
  clipboardText = ''
  FakeEventSource.instances = []
  FakeNotification.shown = []
  FakeNotification.permission = 'granted'
  vi.stubGlobal('EventSource', FakeEventSource)
  vi.stubGlobal('Notification', FakeNotification)
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
    token: 'mercure-jwt',
    topics: [`/user/${USER_ID}/mining-operations`],
    hubUrl: '/.well-known/mercure',
  }), { status: 200 })))
  Object.defineProperty(window.navigator, 'clipboard', { configurable: true, value: { readText: vi.fn(async () => clipboardText) } })
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => answer(endpoint, options) as never)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

enableAutoUnmount(afterEach)

describe('MiningCompanionHost lot 2', () => {
  it('starts the next site from the form, closes the form and shows the new site', async () => {
    const { companion } = mountHost(OPERATION_TARGET)
    await settle()
    const siteBefore = textOf('companion-site')

    await openNextSiteForm()
    await submitNextSite('Belt Jaspet')

    expect({
      siteBefore,
      posted: bodiesPosted(NEXT_SITE_PATH),
      siteAfter: textOf('companion-site'),
      formOpen: exists('companion-next-site-form'),
      notice: textOf('companion-notice'),
      target: companion.target,
    }).toEqual({
      siteBefore: 'Belt Aldranette',
      posted: [{ label: 'Belt Jaspet', solarSystemId: null }],
      siteAfter: 'Belt Jaspet',
      formOpen: false,
      notice: expect.stringContaining('Belt Jaspet'),
      target: OPERATION_TARGET,
    })
  })

  it('shows the refusal of the server in the form, keeps the form open and keeps the displayed site when the next site is forbidden', async () => {
    world.nextSiteFailure = new ApiError(FORBIDDEN_MESSAGE, 403)
    mountHost(OPERATION_TARGET)
    await settle()

    await openNextSiteForm()
    await submitNextSite('Belt Jaspet')

    expect({
      error: textOf('companion-next-site-error'),
      formOpen: exists('companion-next-site-form'),
      site: textOf('companion-site'),
    }).toEqual({
      error: FORBIDDEN_MESSAGE,
      formOpen: true,
      site: 'Belt Aldranette',
    })
  })

  it('follows the next site when the event closes the displayed site and ignores events that do not close it or carry no usable data', async () => {
    const receive = await connectedEvents()
    const { companion } = mountHost({ siteId: SITE_ID, operationId: null })
    await settle()
    const initial = companion.target?.siteId

    receive({ action: 'site_next_started', data: { siteId: OTHER_SITE_ID, closedSiteIds: [UNRELATED_SITE_ID] } })
    await settle()
    const afterOtherClosed = companion.target?.siteId
    receive({ action: 'site_next_started', data: null })
    await settle()
    const afterNoData = companion.target?.siteId
    receive({ action: 'site_next_started', data: { siteId: 42, closedSiteIds: SITE_ID } })
    await settle()
    const afterMalformed = companion.target?.siteId
    receive({ action: 'site_next_started', data: { siteId: OTHER_SITE_ID, closedSiteIds: [SITE_ID] } })
    await settle()

    expect({ initial, afterOtherClosed, afterNoData, afterMalformed, final: companion.target?.siteId, size: companion.size }).toEqual({
      initial: SITE_ID,
      afterOtherClosed: SITE_ID,
      afterNoData: SITE_ID,
      afterMalformed: SITE_ID,
      final: OTHER_SITE_ID,
      size: 'compact',
    })
  })

  it('loads the operation and its sites for the companion without touching the operation and the sites shown by the page', async () => {
    const pageOperation = startedSpodumainOperation({ id: OPERATION_P2, label: 'Anomalie Veldspar — 18:00' })
    const pageSites = [siteOf({ id: UNRELATED_SITE_ID, label: 'Belt Page', operationId: OPERATION_P2 })]
    const operationStore = useMiningOperationStore()
    const siteStore = useMiningSiteStore()
    operationStore.currentOperation = pageOperation
    siteStore.operationSites = pageSites

    mountHost(OPERATION_TARGET)
    await settle()

    expect({
      companionSite: textOf('companion-site'),
      currentOperation: operationStore.currentOperation,
      operationError: operationStore.error,
      operationSites: siteStore.operationSites,
      siteError: siteStore.error,
    }).toEqual({
      companionSite: 'Belt Aldranette',
      currentOperation: pageOperation,
      operationError: null,
      operationSites: pageSites,
      siteError: null,
    })
  })

  it('notifies once when a site becomes empty in the window, and not for a site that was already empty when the window opened', async () => {
    const receive = await connectedEvents()
    const first = mountHost(OPERATION_TARGET)
    first.companion.settings = { ...first.companion.settings, notifications: true }
    await settle()
    const beforeDepletion = FakeNotification.shown.length

    world.scansById[SITE_ID] = depletedScans()
    receive({ action: 'site_scan_recorded', data: { siteId: SITE_ID } })
    await settle()
    receive({ action: 'site_scan_recorded', data: { siteId: SITE_ID } })
    await settle()
    const afterDepletion = [...FakeNotification.shown]
    first.wrapper.unmount()
    mountHost(OPERATION_TARGET)
    await settle()

    expect({ beforeDepletion, afterDepletion, afterReopening: FakeNotification.shown.length }).toEqual({
      beforeDepletion: 0,
      afterDepletion: [{
        title: first.t('miningCompanion.notification.depletedTitle'),
        options: { body: 'Belt Aldranette', tag: `companion-depleted-${SITE_ID}`, silent: true },
      }],
      afterReopening: 1,
    })
  })

  it('records the valid scan of the clipboard when the window regains focus, once for the same text, and ignores a text that is not a scan silently', async () => {
    const { companion } = mountHost(OPERATION_TARGET)
    companion.settings = { ...companion.settings, clipboardOnFocus: true }
    await settle()

    clipboardText = 'bonjour'
    await focusCompanionWindow()
    const afterGreeting = { posted: bodiesPosted(SCANS_PATH), notice: textOf('companion-notice') }
    clipboardText = VALID_SCAN_TEXT
    await focusCompanionWindow()
    const afterValid = bodiesPosted(SCANS_PATH)
    await focusCompanionWindow()

    expect({ afterGreeting, afterValid, afterSameText: bodiesPosted(SCANS_PATH) }).toEqual({
      afterGreeting: { posted: [], notice: null },
      afterValid: [{ lines: [{ typeId: VELDSPAR, units: 10_000 }] }],
      afterSameText: [{ lines: [{ typeId: VELDSPAR, units: 10_000 }] }],
    })
  })
})
