import { createApp, reactive, type Component, type Ref } from 'vue'
import { enableAutoUnmount, flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import { useNavigationEntries, type NavigationEntry } from '@/composables/useNavigationEntries'
import { useTabQuery } from '@/composables/useTabQuery'
import { apiRequest } from '@/services/api'
import Balance from '@/views/Balance.vue'
import Industry from '@/views/Industry.vue'
import MarketBrowser from '@/views/MarketBrowser.vue'
import GroupIndustryList from '@/views/GroupIndustry/GroupIndustryList.vue'
import MiningOperationList from '@/views/MiningOperation/MiningOperationList.vue'
import MiningMoons from '@/views/Mining/MiningMoons.vue'
import MiningMyMining from '@/views/Mining/MiningMyMining.vue'

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

interface ViewOfRoute {
  component: Component
  props?: Record<string, string>
}

const VIEWS_BY_ROUTE: Record<string, ViewOfRoute> = {
  '/balance': { component: Balance },
  '/industry/analysis': { component: Industry, props: { page: 'analysis' } },
  '/industry/production': { component: Industry, props: { page: 'production' } },
  '/industry/projects': { component: GroupIndustryList },
  '/mining/operations': { component: MiningOperationList },
  '/mining/moons': { component: MiningMoons },
  '/mining/my-mining': { component: MiningMyMining },
  '/market': { component: MarketBrowser },
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

const TAB_ROUTES = listAllNavigationEntries().filter(entry => entry.isTab && entry.route.includes('?tab=')).map(entry => entry.route)

async function activeTabOfViewOpenedAt(path: string, tab: string): Promise<string> {
  const view = VIEWS_BY_ROUTE[path]
  if (view === undefined) return `no view mounted at ${path}`
  route.path = path
  route.query = { tab }
  shallowMount(view.component, {
    props: view.props,
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  const activeTabs = vi.mocked(useTabQuery).mock.results.map(result => (result.value as Ref<string>).value)
  if (activeTabs.length === 0) return 'no tab read from the URL'
  return activeTabs.includes(tab) ? tab : activeTabs[0]
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
      '/industry/projects?tab=available',
      '/industry/analysis?tab=margins',
      '/industry/analysis?tab=batch',
      '/industry/analysis?tab=buy-vs-build',
      '/industry/analysis?tab=pivot',
      '/industry/production?tab=slots',
      '/industry/production?tab=stockpile',
      '/industry/production?tab=blueprints',
      '/mining/operations?tab=available',
      '/mining/operations?tab=archive',
      '/mining/moons?tab=tax',
      '/mining/my-mining?tab=season',
      '/market?tab=alerts',
      '/balance?tab=industry',
      '/balance?tab=settings',
    ])
    expect(TAB_ROUTES.map(tabRoute => splitTabRoute(tabRoute).path).every(path => path in VIEWS_BY_ROUTE)).toBe(true)
  })

  it.each(TAB_ROUTES)('opens %s on the tab named in the URL, not on the default tab', async (tabRoute) => {
    const { path, tab } = splitTabRoute(tabRoute)

    expect(await activeTabOfViewOpenedAt(path, tab)).toBe(tab)
  })
})
