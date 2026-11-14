import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Assets from '@/views/Assets.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import { AUTH_LOGOUT_EVENT } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useRateLimitStore } from '@/stores/rateLimit'
import type { User } from '@/types'
import { assetBrowseFetch } from '../assetBrowseApi'
import { present } from '../present'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock('@/router', () => ({ default: { push: vi.fn().mockResolvedValue(undefined) } }))

const REFRESH_ENDPOINT = '/api/me/corporation/assets/refresh'
const LOCATIONS_ENDPOINT = '/api/me/corporation/assets/locations'
const VISIBILITY_ENDPOINT = '/api/me/corporation/assets/visibility'
const STORED_TOKEN = 'stored-token'
const AJAX_HEADER_VALUE = 'XMLHttpRequest'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const EMPTY_SUMMARY = {
  id: 'corporation',
  itemCount: 0,
  typeCount: 0,
  systemCount: 0,
  locationCount: 0,
  lastSyncedAt: null,
  nextRefreshAt: null,
  locations: [],
}

interface Answer {
  status: number
  body: Record<string, unknown>
}

async function mountCorporationAssets(answers: Record<string, Answer> = {}, beforeMount: () => void = () => {}) {
  localStorage.setItem('jwt_token', STORED_TOKEN)
  const pinia = createPinia()
  setActivePinia(pinia)
  const authStore = useAuthStore()
  authStore.token = 'test-token'
  authStore.user = {
    id: '00000000-0000-4000-8000-0000000000u1',
    authStatus: 'valid',
    mainCharacter: null,
    characters: [],
    corporationId: 98000001,
    corporationName: 'Test Corp',
  } as unknown as User
  beforeMount()
  const defaults: Record<string, Answer> = {
    [VISIBILITY_ENDPOINT]: { status: 200, body: { isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null } },
  }
  const merged = { ...defaults, ...answers }
  const fetchMock = vi.fn(assetBrowseFetch([], url => {
    const answer = merged[url]
    return answer === undefined ? null : new Response(JSON.stringify(answer.body), { status: answer.status })
  }))
  vi.stubGlobal('fetch', fetchMock)
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        OpenInGameButton: true,
        AssetDistributionChart: true,
        CorpVisibilityPanel: true,
        ContractsTab: true,
      },
    },
  })
  await flushPromises()
  wrapper.findComponent(AssetsFilterBar).vm.$emit('update:view-mode', 'corporation')
  await flushPromises()
  return { wrapper, fetchMock }
}

function requestsTo(fetchMock: ReturnType<typeof vi.fn>, endpoint: string) {
  return fetchMock.mock.calls.filter(([input]) => String(input) === endpoint) as [RequestInfo | URL, RequestInit][]
}

async function clickRefresh(wrapper: ReturnType<typeof mount>) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === 'Refresh')
  await present(button).trigger('click')
  await flushPromises()
}

describe('Assets list request goes through apiRequest', () => {
  it('sends the stored session token and the ajax header', async () => {
    const { fetchMock } = await mountCorporationAssets()

    const [, init] = present(requestsTo(fetchMock, LOCATIONS_ENDPOINT)[0])
    const headers = new Headers(init.headers)
    expect(headers.get('Authorization')).toBe(`Bearer ${STORED_TOKEN}`)
    expect(headers.get('X-Requested-With')).toBe(AJAX_HEADER_VALUE)
  })

  it('logs the user out when the list answers 401', async () => {
    const logoutListener = vi.fn()
    window.addEventListener(AUTH_LOGOUT_EVENT, logoutListener)

    await mountCorporationAssets({ [LOCATIONS_ENDPOINT]: { status: 401, body: {} } })

    window.removeEventListener(AUTH_LOGOUT_EVENT, logoutListener)
    expect(logoutListener).toHaveBeenCalled()
  })

  it('does not call the list while the ESI rate limit cooldown is active and shows the load failure', async () => {
    const { wrapper, fetchMock } = await mountCorporationAssets({}, () => useRateLimitStore().setRateLimited())

    expect(requestsTo(fetchMock, LOCATIONS_ENDPOINT)).toHaveLength(0)
    expect(wrapper.text()).toContain(en.assets.loadFailed)
    useRateLimitStore().clearRateLimit()
  })

  it('still reads the items of a successful list answer', async () => {
    const { wrapper } = await mountCorporationAssets({
      [LOCATIONS_ENDPOINT]: { status: 200, body: { ...EMPTY_SUMMARY, nextRefreshAt: '2026-11-13T12:00:00+00:00' } },
    })

    expect(wrapper.text()).not.toContain(en.assets.loadFailed)
  })
})

describe('Assets refresh request goes through apiRequest', () => {
  it('posts with the stored session token and the ajax header', async () => {
    const { wrapper, fetchMock } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 200, body: { status: 'completed' } } })

    await clickRefresh(wrapper)

    const [, init] = present(requestsTo(fetchMock, REFRESH_ENDPOINT)[0])
    const headers = new Headers(init.headers)
    expect(init.method).toBe('POST')
    expect(headers.get('Authorization')).toBe(`Bearer ${STORED_TOKEN}`)
    expect(headers.get('X-Requested-With')).toBe(AJAX_HEADER_VALUE)
  })

  it('logs the user out when the refresh answers 401', async () => {
    const logoutListener = vi.fn()
    window.addEventListener(AUTH_LOGOUT_EVENT, logoutListener)
    const { wrapper } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 401, body: {} } })

    await clickRefresh(wrapper)

    window.removeEventListener(AUTH_LOGOUT_EVENT, logoutListener)
    expect(logoutListener).toHaveBeenCalled()
  })

  it('keeps showing the translated fallback when the refresh fails without a server message', async () => {
    const { wrapper } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 500, body: {} } })

    await clickRefresh(wrapper)

    expect(wrapper.text()).toContain(en.assets.refreshFailed)
    expect(wrapper.text()).not.toContain('API error')
  })

  it('keeps showing the server message when the refresh is refused', async () => {
    const { wrapper } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 403, body: { error: 'Not a Director' } } })

    await clickRefresh(wrapper)

    expect(wrapper.text()).toContain('Not a Director')
  })
})
