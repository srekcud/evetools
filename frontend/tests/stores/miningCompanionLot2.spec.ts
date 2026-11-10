import { createPinia, setActivePinia } from 'pinia'
import { ApiError, apiRequest } from '@/services/api'
import { useMiningCompanionStore } from '@/stores/miningCompanion'
import { useMiningOperationStore } from '@/stores/miningOperation'
import { useMiningSiteStore } from '@/stores/miningSite'
import { openCompanionWindow } from '@/views/MiningSite/miningCompanion'
import { OPERATION_P2, startedSpodumainOperation } from '../views/MiningOperation/miningOperationLedgerFixtures'
import { ALDRANETTE, JASPET, OPERATION_ID, OTHER_SITE_ID, SITES_PATH, SITE_ID, siteOf } from '../views/MiningSite/miningSiteFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

vi.mock(import('@/views/MiningSite/miningCompanion'), async importOriginal => ({
  ...(await importOriginal()),
  openCompanionWindow: vi.fn(),
}))

const OPERATION_PATH = `/mining-operations/${OPERATION_ID}`
const DEPART_PATH = `${OPERATION_PATH}/depart`
const NEXT_SITE_PATH = `${OPERATION_PATH}/next-site`
const OPERATION_SITES_PATH = `${SITES_PATH}?operationId=${OPERATION_ID}`
const OTHER_OPERATION_SITES_PATH = `${SITES_PATH}?operationId=${OPERATION_P2}`
const NOTIFICATIONS_KEY = 'miningCompanion.notifications'
const CLIPBOARD_KEY = 'miningCompanion.clipboardOnFocus'

async function outcomeOf<T>(call: Promise<T>): Promise<{ value: T } | { rejection: string }> {
  const [settled] = await Promise.allSettled([call])
  return settled.status === 'fulfilled' ? { value: settled.value } : { rejection: String(settled.reason) }
}

function requestsOf(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(call => `${call[1]?.method ?? 'GET'} ${call[0]}`)
}

function fakeWindow() {
  return {
    focus: vi.fn(),
    resizeTo: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    close: vi.fn(),
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(openCompanionWindow).mockReset()
})

describe('mining companion lot 2 stores', () => {
  it('returns the operation, the departure and the sites of the operation without touching the state of the page and rethrows a failure without recording it', async () => {
    const operation = startedSpodumainOperation()
    const departed = startedSpodumainOperation({ myDepartedAt: '2026-11-05T20:30:00+00:00' })
    const sites = [siteOf({ id: SITE_ID, operationId: OPERATION_ID }), siteOf({ id: OTHER_SITE_ID, operationId: OPERATION_ID, label: 'Belt Jaspet' })]
    const pageOperation = startedSpodumainOperation({ id: OPERATION_P2, label: 'Anomalie Veldspar — 18:00' })
    const pageSites = [siteOf({ id: OTHER_SITE_ID, label: 'Belt Page', operationId: OPERATION_P2 })]
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint === OPERATION_PATH) return operation as never
      if (endpoint === DEPART_PATH) return departed as never
      if (endpoint === OPERATION_SITES_PATH) return sites as never
      throw new ApiError('Mining operation not found', 404)
    })
    const operationStore = useMiningOperationStore()
    const siteStore = useMiningSiteStore()
    operationStore.currentOperation = pageOperation
    siteStore.operationSites = pageSites

    const read = await outcomeOf(operationStore.readOperation(OPERATION_ID))
    const left = await outcomeOf(operationStore.leaveOperation(OPERATION_ID))
    const readSites = await outcomeOf(siteStore.readOperationSites(OPERATION_ID))
    const readFailure = await outcomeOf(operationStore.readOperation(OPERATION_P2))
    const readSitesFailure = await outcomeOf(siteStore.readOperationSites(OPERATION_P2))

    expect({
      read,
      left,
      readSites,
      readFailure,
      readSitesFailure,
      requests: requestsOf(),
      departBody: JSON.parse(String(vi.mocked(apiRequest).mock.calls[1]?.[1]?.body ?? 'null')),
      currentOperation: operationStore.currentOperation,
      operationError: operationStore.error,
      operationSites: siteStore.operationSites,
      siteError: siteStore.error,
    }).toEqual({
      read: { value: operation },
      left: { value: departed },
      readSites: { value: sites },
      readFailure: { rejection: 'ApiError: Mining operation not found' },
      readSitesFailure: { rejection: 'ApiError: Mining operation not found' },
      requests: [`GET ${OPERATION_PATH}`, `POST ${DEPART_PATH}`, `GET ${OPERATION_SITES_PATH}`, `GET /mining-operations/${OPERATION_P2}`, `GET ${OTHER_OPERATION_SITES_PATH}`],
      departBody: {},
      currentOperation: pageOperation,
      operationError: null,
      operationSites: pageSites,
      siteError: null,
    })
  })

  it('reloads the sites of the page after a next site only when they belong to the same operation or were never loaded', async () => {
    const started = siteOf({ id: OTHER_SITE_ID, label: 'Belt Jaspet', solarSystemId: JASPET, solarSystemName: 'Jaspet', operationId: OPERATION_ID })
    const otherOperationSites = [siteOf({ id: SITE_ID, label: 'Belt Page', operationId: OPERATION_P2 })]
    const sameOperationSites = [started, siteOf({ id: SITE_ID, status: 'closed', operationId: OPERATION_ID })]
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
      if (endpoint === NEXT_SITE_PATH && options?.method === 'POST') return started as never
      if (endpoint === OPERATION_SITES_PATH) return sameOperationSites as never
      if (endpoint === OTHER_OPERATION_SITES_PATH) return otherOperationSites as never
      throw new Error(`Unexpected request ${endpoint}`)
    })
    const input = { label: 'Belt Jaspet', solarSystemId: ALDRANETTE }

    const pageOnOtherOperation = useMiningSiteStore()
    await pageOnOtherOperation.fetchOperationSites(OPERATION_P2)
    vi.mocked(apiRequest).mockClear()
    await pageOnOtherOperation.startNextSite(OPERATION_ID, input)
    const otherOperation = { requests: requestsOf(), operationSites: pageOnOtherOperation.operationSites }

    setActivePinia(createPinia())
    const pageOnSameOperation = useMiningSiteStore()
    await pageOnSameOperation.fetchOperationSites(OPERATION_ID)
    vi.mocked(apiRequest).mockClear()
    await pageOnSameOperation.startNextSite(OPERATION_ID, input)
    const sameOperation = { requests: requestsOf(), operationSites: pageOnSameOperation.operationSites }

    setActivePinia(createPinia())
    const pageNeverLoaded = useMiningSiteStore()
    vi.mocked(apiRequest).mockClear()
    await pageNeverLoaded.startNextSite(OPERATION_ID, input)
    const neverLoaded = { requests: requestsOf(), operationSites: pageNeverLoaded.operationSites }

    expect({ otherOperation, sameOperation, neverLoaded }).toEqual({
      otherOperation: { requests: [`POST ${NEXT_SITE_PATH}`], operationSites: otherOperationSites },
      sameOperation: { requests: [`POST ${NEXT_SITE_PATH}`, `GET ${OPERATION_SITES_PATH}`], operationSites: sameOperationSites },
      neverLoaded: { requests: [`POST ${NEXT_SITE_PATH}`, `GET ${OPERATION_SITES_PATH}`], operationSites: sameOperationSites },
    })
  })

  it('remembers the notification and clipboard settings in the browser, reads garbage as false and retargets without changing the size nor the window', async () => {
    const opened = fakeWindow()
    vi.mocked(openCompanionWindow).mockResolvedValue({ kind: 'pip', window: opened as unknown as Window, notice: null, cause: null })
    const store = useMiningCompanionStore()
    const defaults = { notifications: store.settings.notifications, clipboardOnFocus: store.settings.clipboardOnFocus }

    store.setNotifications(true)
    store.setClipboardOnFocus(true)
    const enabled = { notifications: store.settings.notifications, clipboardOnFocus: store.settings.clipboardOnFocus }
    setActivePinia(createPinia())
    const reloaded = useMiningCompanionStore()
    const afterReload = { notifications: reloaded.settings.notifications, clipboardOnFocus: reloaded.settings.clipboardOnFocus }
    reloaded.setNotifications(false)
    setActivePinia(createPinia())
    const afterDisabling = { notifications: useMiningCompanionStore().settings.notifications, clipboardOnFocus: useMiningCompanionStore().settings.clipboardOnFocus }
    localStorage.setItem(NOTIFICATIONS_KEY, 'banana')
    localStorage.setItem(CLIPBOARD_KEY, '{')
    setActivePinia(createPinia())
    const afterGarbage = { notifications: useMiningCompanionStore().settings.notifications, clipboardOnFocus: useMiningCompanionStore().settings.clipboardOnFocus }

    setActivePinia(createPinia())
    const companion = useMiningCompanionStore()
    await companion.detach({ siteId: SITE_ID, operationId: null })
    companion.setSize('detail')
    opened.resizeTo.mockClear()
    companion.retarget({ siteId: OTHER_SITE_ID, operationId: OPERATION_ID })

    expect({
      defaults,
      enabled,
      afterReload,
      afterDisabling,
      afterGarbage,
      target: companion.target,
      size: companion.size,
      sameWindow: companion.companionWindow === (opened as unknown as Window),
      resized: opened.resizeTo.mock.calls.length,
    }).toEqual({
      defaults: { notifications: false, clipboardOnFocus: false },
      enabled: { notifications: true, clipboardOnFocus: true },
      afterReload: { notifications: true, clipboardOnFocus: true },
      afterDisabling: { notifications: false, clipboardOnFocus: true },
      afterGarbage: { notifications: false, clipboardOnFocus: false },
      target: { siteId: OTHER_SITE_ID, operationId: OPERATION_ID },
      size: 'detail',
      sameWindow: true,
      resized: 0,
    })
  })
})
