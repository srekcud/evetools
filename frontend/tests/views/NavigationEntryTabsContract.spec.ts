import { createApp, reactive, type Component, type Ref } from 'vue'
import { enableAutoUnmount, flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import { useNavigationEntries, type NavigationEntry } from '@/composables/useNavigationEntries'
import { useTabQuery } from '@/composables/useTabQuery'
import { apiRequest } from '@/services/api'
import Assets from '@/views/Assets.vue'
import Industry from '@/views/Industry.vue'
import Ledger from '@/views/Ledger.vue'
import MarketBrowser from '@/views/MarketBrowser.vue'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'

const route = reactive<{ path: string; params: Record<string, string>; query: LocationQuery }>({ path: '/', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

vi.mock(import('@/composables/useTabQuery'), async importOriginal => {
  const original = await importOriginal()
  return { useTabQuery: vi.fn(original.useTabQuery) as typeof original.useTabQuery }
})

const VIEWS_BY_ROUTE: Record<string, Component> = {
  '/ledger': Ledger,
  '/industry': Industry,
  '/group-industry': GroupIndustryList,
  '/market': MarketBrowser,
  '/assets': Assets,
}

function listAllNavigationEntries(): NavigationEntry[] {
  let entries: NavigationEntry[] = []
  const app = createApp({
    setup: () => {
      entries = useNavigationEntries().listNavigationEntries({ isAdmin: true })
      return () => null
    },
  })
  app.use(createI18n({ legacy: false, locale: 'en', messages: { en } }))
  app.mount(document.createElement('div'))
  app.unmount()
  return entries
}

function splitTabRoute(tabRoute: string): { path: string; tab: string } {
  const url = new URL(tabRoute, 'https://evetools.test')
  return { path: url.pathname, tab: url.searchParams.get('tab') ?? '' }
}

const TAB_ROUTES = listAllNavigationEntries().filter(entry => entry.isTab).map(entry => entry.route)

async function activeTabOfViewOpenedAt(path: string, tab: string): Promise<string> {
  route.path = path
  route.query = { tab }
  shallowMount(VIEWS_BY_ROUTE[path], {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  const activeTab = vi.mocked(useTabQuery).mock.results[0].value as Ref<string>
  return activeTab.value
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.mocked(useTabQuery).mockClear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockReturnValue(new Promise(() => {}))
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  vi.stubGlobal('EventSource', class { close(): void {} })
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('navigation entry tabs contract', () => {
  it('lists tab entries only for the screens whose view reads the tab from the URL', () => {
    expect(TAB_ROUTES).toEqual([
      '/ledger?tab=pve',
      '/ledger?tab=mining',
      '/ledger?tab=escalations',
      '/ledger?tab=settings',
      '/industry?tab=projects',
      '/industry?tab=margins',
      '/industry?tab=batch',
      '/industry?tab=buy-vs-build',
      '/industry?tab=pivot',
      '/industry?tab=slots',
      '/industry?tab=stockpile',
      '/group-industry?tab=available',
      '/market?tab=alerts',
      '/assets?tab=contracts',
    ])
    expect(TAB_ROUTES.map(tabRoute => splitTabRoute(tabRoute).path).every(path => path in VIEWS_BY_ROUTE)).toBe(true)
  })

  it.each(TAB_ROUTES)('opens %s on the tab named in the URL, not on the default tab', async (tabRoute) => {
    const { path, tab } = splitTabRoute(tabRoute)

    expect(await activeTabOfViewOpenedAt(path, tab)).toBe(tab)
  })
})
