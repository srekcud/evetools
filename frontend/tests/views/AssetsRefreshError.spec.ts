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
import type { User } from '@/types'
import { present } from '../present'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const NO_DIRECTOR_MESSAGE = 'No Director connected. A Director of the corporation must link a character.'
const FALLBACK_REFRESH_ERROR = 'The asset refresh failed.'
const REFRESH_ENDPOINT = '/api/me/corporation/assets/refresh'
const ASSETS_ENDPOINT = '/api/me/corporation/assets'
const VISIBILITY_ENDPOINT = '/api/me/corporation/assets/visibility'

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.unstubAllGlobals()
})

async function mountCorporationAssets(refreshAnswer: Record<string, unknown>, locale: 'en' | 'fr' = 'en') {
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
  const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    const answers: Record<string, Record<string, unknown>> = {
      [REFRESH_ENDPOINT]: refreshAnswer,
      [VISIBILITY_ENDPOINT]: { isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null },
    }
    const body = answers[url] ?? { items: [] }
    return new Response(JSON.stringify(body), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
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
  return { wrapper, fetchMock }
}

async function clickRefresh(wrapper: ReturnType<typeof mount>) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === 'Refresh')
  expect(button).toBeDefined()
  await present(button).trigger('click')
  await flushPromises()
  return present(button)
}

function assetsFetchCount(fetchMock: ReturnType<typeof vi.fn>) {
  return fetchMock.mock.calls.filter(([input]) => String(input) === ASSETS_ENDPOINT).length
}

describe('Corporation assets refresh answered with an error status', () => {
  it('shows the error text the server returned', async () => {
    const { wrapper } = await mountCorporationAssets({ status: 'error', error: NO_DIRECTOR_MESSAGE })

    await clickRefresh(wrapper)

    expect(wrapper.text()).toContain(NO_DIRECTOR_MESSAGE)
  })

  it('releases the refresh button after showing the error', async () => {
    const { wrapper } = await mountCorporationAssets({ status: 'error', error: NO_DIRECTOR_MESSAGE })

    const button = await clickRefresh(wrapper)

    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('shows a translated message when the answer carries no error text', async () => {
    const { wrapper } = await mountCorporationAssets({ status: 'error' })

    await clickRefresh(wrapper)

    expect(wrapper.text()).toContain(FALLBACK_REFRESH_ERROR)
  })

  it('shows the same fallback message in French', async () => {
    const { wrapper } = await mountCorporationAssets({ status: 'error' }, 'fr')
    const button = wrapper.findAll('button').find(candidate => candidate.text() === 'Actualiser' || candidate.text() === 'Rafraîchir')
    expect(button).toBeDefined()

    await present(button).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain(fr.assets.refreshFailed)
    expect(fr.assets.refreshFailed).not.toBe(en.assets.refreshFailed)
  })
})

describe('Corporation assets refresh answered with a completed status', () => {
  it('shows no error and reloads the list', async () => {
    const { wrapper, fetchMock } = await mountCorporationAssets({ status: 'completed' })
    const fetchesBeforeRefresh = assetsFetchCount(fetchMock)

    await clickRefresh(wrapper)

    expect(wrapper.text()).not.toContain(FALLBACK_REFRESH_ERROR)
    expect(assetsFetchCount(fetchMock)).toBe(fetchesBeforeRefresh + 1)
  })
})
