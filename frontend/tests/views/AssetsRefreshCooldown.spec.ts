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
import { assetBrowseFetch } from '../assetBrowseApi'
import { present } from '../present'

const route = reactive<{ query: LocationQuery; params: Record<string, string> }>({ query: {}, params: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

const REFRESH_ENDPOINT = '/api/me/corporation/assets/refresh'
const VISIBILITY_ENDPOINT = '/api/me/corporation/assets/visibility'
const LOCATIONS_ENDPOINT = '/api/me/corporation/assets/locations'
const NOW = '2026-11-13T12:10:00+00:00'
const LAST_SYNC = '2026-11-13T12:00:00+00:00'
const NEXT_REFRESH = '2026-11-13T13:00:00+00:00'
const MINUTES_UNTIL_NEXT_REFRESH = 50
const MILLISECONDS_PER_MINUTE = 60_000
const AVAILABLE_AT_TEXT = 'Refresh available at 13:00'
const ALREADY_RUNNING_TEXT = 'A sync of the corporation assets is already running'
const FALLBACK_REFRESH_ERROR = 'The asset refresh failed.'
const EMPTY_SUMMARY = {
  id: 'corporation',
  itemCount: 0,
  typeCount: 0,
  systemCount: 0,
  locationCount: 0,
  lastSyncedAt: null,
  locations: [],
}

enableAutoUnmount(afterEach)

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

async function mountCorporationAssets(options: { summaryAnswer?: Record<string, unknown>; refreshAnswer?: Record<string, unknown> } = {}) {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
  vi.setSystemTime(new Date(NOW))
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
  const fetchMock = vi.fn(assetBrowseFetch([], url => {
    const answers: Record<string, Record<string, unknown>> = {
      [REFRESH_ENDPOINT]: options.refreshAnswer ?? { status: 'pending' },
      [VISIBILITY_ENDPOINT]: { isDirector: true, accessibleDivisions: [], allDivisions: {}, updatedAt: null },
      ...(options.summaryAnswer === undefined ? {} : { [LOCATIONS_ENDPOINT]: options.summaryAnswer }),
    }
    return url in answers ? new Response(JSON.stringify(answers[url]), { status: 200 }) : null
  }))
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
  return { wrapper, fetchMock }
}

function refreshButton(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').find(candidate => /^Refresh/.test(candidate.text().trim()))
}

async function clickRefresh(wrapper: ReturnType<typeof mount>) {
  await present(refreshButton(wrapper)).trigger('click')
  await flushPromises()
}

describe('Corporation assets refresh button during the cooldown', () => {
  it('is disabled and tells when the next refresh is possible', async () => {
    const { wrapper } = await mountCorporationAssets({ summaryAnswer: { ...EMPTY_SUMMARY, nextRefreshAt: NEXT_REFRESH } })

    const button = refreshButton(wrapper)

    expect([button?.text().trim(), button?.attributes('disabled') !== undefined]).toEqual([AVAILABLE_AT_TEXT, true])
  })

  it('is enabled again when the next refresh time comes, without reloading the page', async () => {
    const { wrapper } = await mountCorporationAssets({ summaryAnswer: { ...EMPTY_SUMMARY, nextRefreshAt: NEXT_REFRESH } })

    await vi.advanceTimersByTimeAsync(MINUTES_UNTIL_NEXT_REFRESH * MILLISECONDS_PER_MINUTE)
    await flushPromises()

    const button = refreshButton(wrapper)
    expect([button?.text().trim(), button?.attributes('disabled')]).toEqual(['Refresh', undefined])
  })

  it('stays enabled when the server gives no next refresh time', async () => {
    const { wrapper } = await mountCorporationAssets({ summaryAnswer: { ...EMPTY_SUMMARY, nextRefreshAt: null } })

    const button = refreshButton(wrapper)

    expect([button?.text().trim(), button?.attributes('disabled')]).toEqual(['Refresh', undefined])
  })

  it('has French labels distinct from the English ones', () => {
    expect(fr.assets.refreshAvailableAt).toContain('{time}')
    expect(fr.assets.refreshAvailableAt).not.toBe(en.assets.refreshAvailableAt)
    expect(fr.assets.syncAlreadyRunning).toBeTruthy()
    expect(fr.assets.syncAlreadyRunning).not.toBe(en.assets.syncAlreadyRunning)
  })
})

describe('Corporation assets refresh answered with up_to_date', () => {
  it('shows no error, stops the spinner and blocks the button until the next refresh time', async () => {
    const { wrapper } = await mountCorporationAssets({
      refreshAnswer: { status: 'up_to_date', message: 'Up to date', syncedAt: LAST_SYNC, nextRefreshAt: NEXT_REFRESH },
    })

    await clickRefresh(wrapper)

    const button = refreshButton(wrapper)
    expect(wrapper.text()).not.toContain(FALLBACK_REFRESH_ERROR)
    expect([button?.text().trim(), button?.attributes('disabled') !== undefined, wrapper.find('svg.animate-spin').exists()]).toEqual([AVAILABLE_AT_TEXT, true, false])
  })
})

describe('Corporation assets refresh answered with in_progress', () => {
  it('says a sync is already running, shows no error and releases the button', async () => {
    const { wrapper } = await mountCorporationAssets({ refreshAnswer: { status: 'in_progress', message: 'Already running' } })

    await clickRefresh(wrapper)

    const button = refreshButton(wrapper)
    expect(wrapper.text()).toContain(ALREADY_RUNNING_TEXT)
    expect(wrapper.text()).not.toContain(FALLBACK_REFRESH_ERROR)
    expect([button?.text().trim(), button?.attributes('disabled')]).toEqual(['Refresh', undefined])
  })
})
