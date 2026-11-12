import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import Assets from '@/views/Assets.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import { SYNC_EVENT_TIMEOUT_MS } from '@/constants/syncConstants'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore, type SyncProgress, type SyncStatus } from '@/stores/sync'
import type { User } from '@/types'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const NO_ANSWER_MESSAGE = 'The sync did not answer.'
const REFRESH_ENDPOINT = '/api/me/corporation/assets/refresh'
const ASSETS_ENDPOINT = '/api/me/corporation/assets'
const VISIBILITY_ENDPOINT = '/api/me/corporation/assets/visibility'
const CORPORATION_SYNC_TYPE = 'corporation-assets'
const JUST_BEFORE_TIMEOUT_MS = SYNC_EVENT_TIMEOUT_MS - 1
const HALF_TIMEOUT_MS = SYNC_EVENT_TIMEOUT_MS / 2

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

function progressEvent(status: SyncStatus): SyncProgress {
  return { syncType: CORPORATION_SYNC_TYPE, status, progress: null, message: null, data: null, timestamp: '2026-11-12T10:00:00+00:00' }
}

async function mountCorporationAssetsRefreshing() {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
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
  const syncStore = useSyncStore()
  syncStore.isConnected = true
  const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const answers: Record<string, Record<string, unknown>> = {
      [REFRESH_ENDPOINT]: { status: 'pending' },
      [VISIBILITY_ENDPOINT]: { isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null },
    }
    return new Response(JSON.stringify(answers[String(input)] ?? { items: [] }), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
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
  const button = wrapper.findAll('button').find(candidate => candidate.text() === 'Refresh')
  await button!.trigger('click')
  await flushPromises()
  return { wrapper, fetchMock, syncStore, button: button! }
}

async function advance(milliseconds: number) {
  await vi.advanceTimersByTimeAsync(milliseconds)
  await flushPromises()
}

function assetsFetchCount(fetchMock: ReturnType<typeof vi.fn>) {
  return fetchMock.mock.calls.filter(([input]) => String(input) === ASSETS_ENDPOINT).length
}

describe('Assets refresh with Mercure connected and no sync event', () => {
  it('keeps waiting until the delay is over', async () => {
    const { wrapper, button } = await mountCorporationAssetsRefreshing()

    await advance(JUST_BEFORE_TIMEOUT_MS)

    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain(NO_ANSWER_MESSAGE)
  })

  it('stops the spinner, reloads the assets and says the sync did not answer once the delay is over', async () => {
    const { wrapper, fetchMock, button } = await mountCorporationAssetsRefreshing()
    const fetchesBeforeTimeout = assetsFetchCount(fetchMock)

    await advance(SYNC_EVENT_TIMEOUT_MS)

    expect(button.attributes('disabled')).toBeUndefined()
    expect(assetsFetchCount(fetchMock)).toBe(fetchesBeforeTimeout + 1)
    expect(wrapper.text()).toContain(NO_ANSWER_MESSAGE)
  })

  it('has a French message distinct from the English one', () => {
    expect(fr.assets.syncNoAnswer).toBeTruthy()
    expect(fr.assets.syncNoAnswer).not.toBe(en.assets.syncNoAnswer)
  })

  it('starts the delay again at each progress event', async () => {
    const { wrapper, syncStore } = await mountCorporationAssetsRefreshing()
    await advance(HALF_TIMEOUT_MS)

    syncStore.syncStatus[CORPORATION_SYNC_TYPE] = progressEvent('in_progress')
    await flushPromises()
    await advance(JUST_BEFORE_TIMEOUT_MS)
    expect(wrapper.text()).not.toContain(NO_ANSWER_MESSAGE)

    await advance(1)
    expect(wrapper.text()).toContain(NO_ANSWER_MESSAGE)
  })

  it('cancels the delay when the completed event arrives before it', async () => {
    const { wrapper, fetchMock, syncStore } = await mountCorporationAssetsRefreshing()
    await advance(HALF_TIMEOUT_MS)

    syncStore.syncStatus[CORPORATION_SYNC_TYPE] = progressEvent('completed')
    await flushPromises()
    const fetchesAfterCompleted = assetsFetchCount(fetchMock)
    await advance(SYNC_EVENT_TIMEOUT_MS)

    expect(wrapper.text()).not.toContain(NO_ANSWER_MESSAGE)
    expect(assetsFetchCount(fetchMock)).toBe(fetchesAfterCompleted)
  })
})
