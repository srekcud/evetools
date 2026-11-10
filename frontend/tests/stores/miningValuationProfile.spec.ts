import { createPinia, setActivePinia } from 'pinia'
import { useMiningValuationProfileStore } from '@/stores/miningValuationProfile'
import {
  BROWSER_EXPORT_TAX,
  BROWSER_STRUCTURE_ID,
  BROWSER_STRUCTURE_NAME,
  DEFAULT_PROFILE,
  LEGACY_EXPORT_TAX_KEY,
  LEGACY_STRUCTURE_KEY,
  PERIMETER_PROFILE,
  miningValuationProfile,
} from '../components/ledger/miningValuationProfileHarness'

const PROFILE_URL = '/api/mining/valuation-profile'
const BROWSER_STRUCTURE = JSON.stringify({ id: BROWSER_STRUCTURE_ID, name: BROWSER_STRUCTURE_NAME })
const UNPERSISTED_PROFILE = miningValuationProfile({ persisted: false })
const IMPORTED_PROFILE = miningValuationProfile({ structureId: BROWSER_STRUCTURE_ID, structureName: BROWSER_STRUCTURE_NAME, exportTax: 800 })

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status })
}

function stubFetch(answer: (init: RequestInit) => Response) {
  const fetchStub = vi.fn(async (_url: string, init: RequestInit) => answer(init))
  vi.stubGlobal('fetch', fetchStub)
  return fetchStub
}

function patchRequests(fetchStub: ReturnType<typeof stubFetch>): RequestInit[] {
  return fetchStub.mock.calls.map(([, init]) => init).filter(init => init.method === 'PATCH')
}

function legacyKeyReads(getItem: { mock: { calls: unknown[][] } }): unknown[][] {
  return getItem.mock.calls.filter(([key]) => key === LEGACY_STRUCTURE_KEY || key === LEGACY_EXPORT_TAX_KEY)
}

function importAnswer(init: RequestInit): Response {
  return init.method === 'PATCH' ? jsonResponse(IMPORTED_PROFILE) : jsonResponse(UNPERSISTED_PROFILE)
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('mining valuation profile store', () => {
  it('reads the mining valuation profile of the user from the server', async () => {
    const fetchStub = stubFetch(() => jsonResponse(PERIMETER_PROFILE))
    const store = useMiningValuationProfileStore()

    await store.load()

    expect(fetchStub.mock.calls.map(([url, init]) => [url, init.method])).toEqual([[PROFILE_URL, undefined]])
    expect(store.profile).toEqual(PERIMETER_PROFILE)
  })

  it('patches only the given fields with the merge-patch content type and keeps the profile the server answers', async () => {
    const updated = miningValuationProfile({ side: 'sell', form: 'raw' })
    const fetchStub = stubFetch(() => jsonResponse(updated))
    const store = useMiningValuationProfileStore()
    store.profile = DEFAULT_PROFILE

    await store.update({ side: 'sell', form: 'raw' })

    expect(fetchStub.mock.calls.map(([url, init]) => [url, init.method, (init.headers as Record<string, string>)['Content-Type']])).toEqual([
      [PROFILE_URL, 'PATCH', 'application/merge-patch+json'],
    ])
    expect(patchRequests(fetchStub).map(init => JSON.parse(String(init.body)))).toEqual([{ side: 'sell', form: 'raw' }])
    expect(store.profile).toEqual(updated)
  })
})

describe('mining valuation profile store, import of the settings kept in the browser', () => {
  it('sends the structure and the export tax of the browser on the Jita market while the profile is not persisted, then removes both keys', async () => {
    localStorage.setItem(LEGACY_STRUCTURE_KEY, BROWSER_STRUCTURE)
    localStorage.setItem(LEGACY_EXPORT_TAX_KEY, BROWSER_EXPORT_TAX)
    const fetchStub = stubFetch(importAnswer)
    const store = useMiningValuationProfileStore()

    await store.load()

    expect(patchRequests(fetchStub).map(init => JSON.parse(String(init.body)))).toEqual([
      { market: 'jita', structureId: BROWSER_STRUCTURE_ID, structureName: BROWSER_STRUCTURE_NAME, exportTax: 800 },
    ])
    expect([localStorage.getItem(LEGACY_STRUCTURE_KEY), localStorage.getItem(LEGACY_EXPORT_TAX_KEY)]).toEqual([null, null])
    expect(store.profile).toEqual(IMPORTED_PROFILE)
  })

  it('keeps both keys to retry the import when the patch fails', async () => {
    localStorage.setItem(LEGACY_STRUCTURE_KEY, BROWSER_STRUCTURE)
    localStorage.setItem(LEGACY_EXPORT_TAX_KEY, BROWSER_EXPORT_TAX)
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const fetchStub = stubFetch(init => init.method === 'PATCH' ? jsonResponse({ error: 'boom' }, 500) : jsonResponse(UNPERSISTED_PROFILE))
    const store = useMiningValuationProfileStore()

    await expect(store.load()).resolves.toBeUndefined()

    expect(patchRequests(fetchStub)).toHaveLength(1)
    expect([localStorage.getItem(LEGACY_STRUCTURE_KEY), localStorage.getItem(LEGACY_EXPORT_TAX_KEY)]).toEqual([BROWSER_STRUCTURE, BROWSER_EXPORT_TAX])
    expect(store.profile).toEqual(UNPERSISTED_PROFILE)
  })

  it('neither patches nor touches the keys of the browser once the profile is persisted', async () => {
    localStorage.setItem(LEGACY_STRUCTURE_KEY, BROWSER_STRUCTURE)
    localStorage.setItem(LEGACY_EXPORT_TAX_KEY, BROWSER_EXPORT_TAX)
    const getItem = vi.spyOn(Storage.prototype, 'getItem')
    const fetchStub = stubFetch(() => jsonResponse(PERIMETER_PROFILE))
    const store = useMiningValuationProfileStore()

    await store.load()

    expect(patchRequests(fetchStub)).toEqual([])
    expect(legacyKeyReads(getItem)).toEqual([])
    getItem.mockRestore()
    expect([localStorage.getItem(LEGACY_STRUCTURE_KEY), localStorage.getItem(LEGACY_EXPORT_TAX_KEY)]).toEqual([BROWSER_STRUCTURE, BROWSER_EXPORT_TAX])
    expect(store.profile).toEqual(PERIMETER_PROFILE)
  })

  it('ignores an unreadable structure of the browser without raising, and logs it to the console', async () => {
    localStorage.setItem(LEGACY_STRUCTURE_KEY, '{"id": 1035')
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const fetchStub = stubFetch(() => jsonResponse(UNPERSISTED_PROFILE))
    const store = useMiningValuationProfileStore()

    await expect(store.load()).resolves.toBeUndefined()

    expect(consoleError).toHaveBeenCalled()
    expect(patchRequests(fetchStub)).toEqual([])
    expect(store.profile).toEqual(UNPERSISTED_PROFILE)
  })
})
