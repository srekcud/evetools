import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import MarketBrowser from '@/views/MarketBrowser.vue'
import MarketTypeDetail from '@/components/market/MarketTypeDetail.vue'
import { apiRequest } from '@/services/api'
import type { MarketTypeDetail as MarketTypeDetailData } from '@/stores/market'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerReplace = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: routerReplace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TYPE_DETAIL_ENDPOINT_PREFIX = '/market/types/'

function typeDetail(typeId: number, typeName: string, jitaSell: number, jitaBuy: number): MarketTypeDetailData {
  return {
    typeId,
    typeName,
    groupName: 'Battleship',
    categoryName: 'Ship',
    jitaSell,
    jitaBuy,
    spread: jitaSell - jitaBuy,
    sellOrders: [],
    buyOrders: [],
    structureSellOrders: [],
    structureBuyOrders: [],
    structureSell: null,
    structureBuy: null,
    structureName: null,
    hasPreferredStructure: false,
    avgDailyVolume: null,
    change30d: null,
    isFavorite: false,
  }
}

const RAVEN_DETAIL = typeDetail(638, 'Raven', 251000000, 238500000)
const RAVEN_NAVY_ISSUE_DETAIL = typeDetail(17636, 'Raven Navy Issue', 612000000, 590250000)
const detailsByEndpoint: Record<string, MarketTypeDetailData> = {
  [`${TYPE_DETAIL_ENDPOINT_PREFIX}638`]: RAVEN_DETAIL,
  [`${TYPE_DETAIL_ENDPOINT_PREFIX}17636`]: RAVEN_NAVY_ISSUE_DETAIL,
}

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  ErrorBanner: true,
  MarketSearch: true,
  MarketResultsTable: true,
  MarketTypeDetail: true,
  AlertsPanel: true,
  LoadingSpinner: true,
}

function stubMarketApi(): void {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint in detailsByEndpoint) {
      return detailsByEndpoint[endpoint]
    }
    if (endpoint === '/me/settings') {
      return { effectiveMarketStructureName: null }
    }
    if (['/market/groups', '/market/favorites', '/market/alerts'].includes(endpoint)) {
      return []
    }
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

function requestedTypeDetails(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(endpoint => endpoint.startsWith(TYPE_DETAIL_ENDPOINT_PREFIX))
}

async function mountMarketWithQuery(query: LocationQuery) {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(MarketBrowser, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  routerReplace.mockClear()
  stubMarketApi()
})

describe('Market type in the URL', () => {
  it('loads and shows the type detail when mounted with the detail tab and a type id', async () => {
    const wrapper = await mountMarketWithQuery({ tab: 'detail', typeId: '638' })

    expect(requestedTypeDetails()).toEqual(['/market/types/638'])
    expect(wrapper.findComponent(MarketTypeDetail).exists()).toBe(true)
    expect(wrapper.findComponent(MarketTypeDetail).props('detail')).toEqual(RAVEN_DETAIL)
  })

  it('loads the type of the query when it changes on a mounted screen', async () => {
    const wrapper = await mountMarketWithQuery({})

    route.query = { tab: 'detail', typeId: '17636' }
    await flushPromises()

    expect(requestedTypeDetails()).toEqual(['/market/types/17636'])
    expect(wrapper.findComponent(MarketTypeDetail).props('detail')).toEqual(RAVEN_NAVY_ISSUE_DETAIL)
  })

  it('loads no type detail outside the detail tab', async () => {
    await mountMarketWithQuery({ tab: 'browse', typeId: '638' })

    expect(requestedTypeDetails()).toEqual([])
  })

  it('loads no type detail for a type id that is not a number', async () => {
    await mountMarketWithQuery({ tab: 'detail', typeId: 'raven' })

    expect(requestedTypeDetails()).toEqual([])
  })
})
