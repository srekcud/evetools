import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive, type Component } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Appraisal from '@/views/Appraisal.vue'
import Contracts from '@/views/Contracts.vue'
import MarketBrowser from '@/views/MarketBrowser.vue'
import { apiRequest, authFetch } from '@/services/api'

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
  authFetch: vi.fn(),
}))

const COMMERCE_NAVIGATION_SELECTOR = 'nav[aria-label="Commerce"]'

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  RouterLink: RouterLinkStub,
  ErrorBanner: true,
  LoadingSpinner: true,
  MarketSearch: true,
  MarketResultsTable: true,
  MarketTypeDetail: true,
  AlertsPanel: true,
  AppraisalInputPanel: true,
  AppraisalResultsTable: true,
  AppraisalActions: true,
  MissingVolumeWarning: true,
  ContractsTab: true,
}

async function mountView(view: Component): Promise<VueWrapper> {
  route.query = {}
  const wrapper = mount(view, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: stubbedChildren },
  })
  await flushPromises()
  return wrapper
}

function currentCommerceTabLabels(wrapper: VueWrapper): string[] {
  return wrapper
    .findAll(`${COMMERCE_NAVIGATION_SELECTOR} a`)
    .filter(link => link.attributes('aria-current') === 'page')
    .map(link => link.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/me/settings') return { effectiveMarketStructureName: 'C-J6MT - 1st Taj Mahgoon' }
    if (['/market/groups', '/market/favorites', '/market/alerts'].includes(endpoint)) return []
    return null
  })
  vi.mocked(authFetch).mockReset()
  vi.mocked(authFetch).mockResolvedValue(new Response('{}', { status: 200 }))
})

describe('Commerce module tabs in the module pages', () => {
  it('shows the Commerce tab bar on the Market page with Market current', async () => {
    const wrapper = await mountView(MarketBrowser)

    expect(currentCommerceTabLabels(wrapper)).toEqual(['Market'])
  })

  it('shows the Commerce tab bar on the Appraisal page with Appraisal current', async () => {
    const wrapper = await mountView(Appraisal)

    expect(currentCommerceTabLabels(wrapper)).toEqual(['Appraisal'])
  })

  it('shows the Commerce tab bar on the Contracts page with Contracts current', async () => {
    const wrapper = await mountView(Contracts)

    expect(currentCommerceTabLabels(wrapper)).toEqual(['Contracts'])
  })
})
