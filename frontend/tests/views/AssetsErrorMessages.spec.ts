import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import Assets from '@/views/Assets.vue'
import AssetsFilterBar from '@/components/assets/AssetsFilterBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { User } from '@/types'
import { present } from '../present'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const REFRESH_ENDPOINT = '/api/me/corporation/assets/refresh'
const ASSETS_ENDPOINT = '/api/me/corporation/assets'
const VISIBILITY_ENDPOINT = '/api/me/corporation/assets/visibility'
const CORPORATION_SYNC_TYPE = 'corporation-assets'
const ENGLISH_LOAD_FAILURE = 'Failed to load assets'
const ENGLISH_REFRESH_FAILURE = 'Failed to refresh'
const ENGLISH_SYNC_FAILURE = 'Sync failed'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

interface Answer {
  status: number
  body: Record<string, unknown>
}

async function mountCorporationAssets(answers: Record<string, Answer>, locale: 'en' | 'fr') {
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
  const defaults: Record<string, Answer> = {
    [VISIBILITY_ENDPOINT]: { status: 200, body: { isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null } },
    [ASSETS_ENDPOINT]: { status: 200, body: { items: [] } },
  }
  const merged = { ...defaults, ...answers }
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const answer = merged[String(input)] ?? { status: 200, body: { items: [] } }
    return new Response(JSON.stringify(answer.body), { status: answer.status })
  }))
  const wrapper = mount(Assets, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale, messages: { en, fr } })],
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
  return { wrapper, syncStore }
}

async function clickRefresh(wrapper: ReturnType<typeof mount>) {
  const button = wrapper.findAll('button').find(candidate => ['Refresh', 'Actualiser', 'Rafraîchir'].includes(candidate.text()))
  expect(button).toBeDefined()
  await present(button).trigger('click')
  await flushPromises()
}

describe('Assets error messages come from the translations', () => {
  it('shows the load failure in French when the list cannot be fetched', async () => {
    const { wrapper } = await mountCorporationAssets({ [ASSETS_ENDPOINT]: { status: 500, body: {} } }, 'fr')

    expect(wrapper.text()).toContain(fr.assets.loadFailed)
    expect(wrapper.text()).not.toContain(ENGLISH_LOAD_FAILURE)
  })

  it('shows the load failure in English when the list cannot be fetched', async () => {
    const { wrapper } = await mountCorporationAssets({ [ASSETS_ENDPOINT]: { status: 500, body: {} } }, 'en')

    expect(wrapper.text()).toContain(en.assets.loadFailed)
  })

  it('shows the refresh failure in French when the refresh answers an error status without a message', async () => {
    const { wrapper } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 500, body: {} } }, 'fr')

    await clickRefresh(wrapper)

    expect(wrapper.text()).toContain(fr.assets.refreshFailed)
    expect(wrapper.text()).not.toContain(ENGLISH_REFRESH_FAILURE)
  })

  it('still shows the message the server returned with an error status', async () => {
    const { wrapper } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 403, body: { error: 'Not a Director' } } }, 'fr')

    await clickRefresh(wrapper)

    expect(wrapper.text()).toContain('Not a Director')
  })

  it('shows the sync failure in French when the sync event carries no message', async () => {
    const { wrapper, syncStore } = await mountCorporationAssets({ [REFRESH_ENDPOINT]: { status: 200, body: { status: 'pending' } } }, 'fr')
    await clickRefresh(wrapper)

    syncStore.syncStatus[CORPORATION_SYNC_TYPE] = {
      syncType: CORPORATION_SYNC_TYPE,
      status: 'error',
      progress: null,
      message: null,
      data: null,
      timestamp: '2026-11-13T10:00:00+00:00',
    }
    await flushPromises()

    expect(wrapper.text()).toContain(fr.common.errors.syncFailed)
    expect(wrapper.text()).not.toContain(ENGLISH_SYNC_FAILURE)
  })
})
