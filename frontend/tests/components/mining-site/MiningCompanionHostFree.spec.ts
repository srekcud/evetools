import { DOMWrapper, enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { apiRequest } from '@/services/api'
import MiningCompanionHost from '@/components/mining-site/MiningCompanionHost.vue'
import { useMiningCompanionStore, type CompanionTarget } from '@/stores/miningCompanion'
import type { MiningSite } from '@/stores/miningSite'
import { SITES_PATH, found, i18nFor, oreValues, siteOf } from '../../views/MiningSite/miningSiteFixtures'
import { response } from '../ledger/moonChunksHarness'
import { CHUNK_A, CHUNK_B, CHUNK_C_EXPLODED, EXTRACTIONS_PATH, FREE_NOW, corporationsOf } from './companionFreeFixtures'

const { router } = vi.hoisted(() => ({ router: { push: vi.fn(), replace: vi.fn() } }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ params: {}, query: {} })) as never,
  useRouter: (() => router) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const FREE_TARGET: CompanionTarget = { siteId: null, operationId: null }
const CREATED_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e77'

let pinia: Pinia
let createdSite: MiningSite | null

function answer(endpoint: string, options?: RequestInit): unknown {
  const method = options?.method ?? 'GET'
  if (endpoint === EXTRACTIONS_PATH) return response(...corporationsOf([CHUNK_A, CHUNK_C_EXPLODED], [CHUNK_B]))
  if (method === 'POST' && endpoint === SITES_PATH) {
    createdSite = siteOf({ id: CREATED_SITE_ID, operationId: null, label: JSON.parse(String(options?.body)).label, solarSystemId: null, solarSystemName: null })
    return createdSite
  }
  if (endpoint.startsWith('/mining/ore-values')) return oreValues()
  const siteMatch = /^\/mining-sites\/([^/?]+)(\/scans)?$/.exec(endpoint)
  if (siteMatch !== null && siteMatch[1] === CREATED_SITE_ID) return siteMatch[2] === undefined ? createdSite : []
  throw new Error(`Unexpected request ${method} ${endpoint}`)
}

function mountHost() {
  const i18n = i18nFor('en')
  const companion = useMiningCompanionStore()
  companion.companionWindow = window
  companion.target = FREE_TARGET
  mount(MiningCompanionHost, { global: { plugins: [pinia, i18n] } })
  return { companion, t: i18n.global.t }
}

function screen(): DOMWrapper<Element> {
  return new DOMWrapper(document.body)
}

function textOf(testId: string): string | null {
  const element = screen().find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

async function settle(): Promise<void> {
  await flushPromises()
  await flushPromises()
}

function requestedPaths(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(call => call[0])
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(FREE_NOW)
  pinia = createPinia()
  setActivePinia(pinia)
  createdSite = null
  router.push.mockReset()
  router.replace.mockReset()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => answer(endpoint, options) as never)
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

enableAutoUnmount(afterEach)

describe('MiningCompanionHost free target', () => {
  it('loads the corporation extractions and shows the next chunk without reading any operation or site', async () => {
    mountHost()
    await settle()

    expect({
      chunk: textOf('companion-free-chunk'),
      countdown: textOf('companion-free-countdown'),
      extractionRequests: requestedPaths().filter(path => path === EXTRACTIONS_PATH).length,
      operationOrSiteRequests: requestedPaths().filter(path => path.startsWith('/mining-operations') || path.startsWith(SITES_PATH)),
    }).toEqual({
      chunk: expect.stringContaining('Jaspet V - Moon 1'),
      countdown: '1h 05m',
      extractionRequests: 1,
      operationOrSiteRequests: [],
    })
  })

  it('focuses the main window and opens the operation creation for the chunk, then the operation list from the desk button', async () => {
    const focus = vi.spyOn(window, 'focus')
    mountHost()
    await settle()

    await found(screen(), '[data-testid="companion-free-create-operation"]').trigger('click')
    const afterCreate = { focused: focus.mock.calls.length, pushed: [...router.push.mock.calls] }
    await found(screen(), '[data-testid="companion-desk"]').trigger('click')

    expect({ afterCreate, pushed: router.push.mock.calls, focused: focus.mock.calls.length }).toEqual({
      afterCreate: { focused: 1, pushed: [[{ name: 'mining-operations', query: { chunk: CHUNK_B.id } }]] },
      pushed: [[{ name: 'mining-operations', query: { chunk: CHUNK_B.id } }], [{ name: 'mining-operations' }]],
      focused: 2,
    })
  })

  it('creates a single site without operation from the estimate form and retargets the companion on it', async () => {
    const { companion } = mountHost()
    await settle()

    await found(screen(), '[data-testid="companion-free-estimate"]').trigger('click')
    await found(screen(), 'input[name="label"]').setValue('Belt Jaspet')
    await found(screen(), '[data-testid="companion-next-site-form"]').trigger('submit')
    await settle()

    const posted = vi.mocked(apiRequest).mock.calls
      .filter(call => call[0] === SITES_PATH && call[1]?.method === 'POST')
      .map(call => JSON.parse(String(call[1]?.body)))

    expect({ posted, target: companion.target }).toEqual({
      posted: [{ label: 'Belt Jaspet', solarSystemId: null }],
      target: { siteId: CREATED_SITE_ID, operationId: null },
    })
  })
})
