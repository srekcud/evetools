import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'
import type { ProfitMarginResult, SearchResult, UserSettings } from '@/stores/industry/types'
import { apiOutcomesByPath, awaiting, deferred, rejectWith, respondWith } from '../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const route = reactive<{ query: LocationQuery }>({ query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn() })) as never,
}))

const TYPE_LOOKUP_ENDPOINT = '/industry/types/'
const PROFIT_MARGIN_ENDPOINT = '/industry/profit-margin/'

const SABRE_TYPE_ID = 990021
const ABSENT_TYPE_ID = 990099

const SABRE: SearchResult = { typeId: SABRE_TYPE_ID, typeName: 'Sabre', isT2: true }

const USER_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  brokerFeeRate: 0.02,
  salesTaxRate: 0.036,
  exportCostPerM3: 950,
}

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  teleport: true,
  ErrorBanner: true,
  ProductSearch: true,
  ProjectTable: true,
  ProjectDetail: true,
  BlacklistConfig: true,
  StructureConfig: true,
  SkillsConfig: true,
  FeesConfig: true,
  IndustryDashboard: true,
  IndustrySlotsSection: true,
  BatchScanTab: true,
  BuyVsBuildTab: true,
  PivotAdvisorTab: true,
  StockpileDashboard: true,
}

const outcomes = apiOutcomesByPath()

function lookupAndMarginRequests(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(endpoint => endpoint.startsWith(TYPE_LOOKUP_ENDPOINT) || endpoint.startsWith(PROFIT_MARGIN_ENDPOINT))
}

async function mountIndustryWithQuery(query: LocationQuery): Promise<VueWrapper> {
  route.query = query
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(Industry, { global: { plugins: [i18n], stubs: stubbedChildren } })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  useIndustryStore().navigationIntent = null
  vi.spyOn(useProjectsStore(), 'fetchProjects').mockResolvedValue(undefined as never)
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === '/industry/settings') return USER_SETTINGS
    const answer = outcomes.answer(endpoint)
    return answer === undefined ? null : answer
  })
})

describe('Industry margins of an opened type (issue 238)', () => {
  it('looks the T2 type of the query up, then analyzes it with ME 2 and TE 4', async () => {
    outcomes.respondTo(`${TYPE_LOOKUP_ENDPOINT}${SABRE_TYPE_ID}`, respondWith(SABRE))
    outcomes.respondTo(`${PROFIT_MARGIN_ENDPOINT}${SABRE_TYPE_ID}`, awaiting(deferred<ProfitMarginResult>()))

    await mountIndustryWithQuery({ tab: 'margins', typeId: String(SABRE_TYPE_ID) })

    expect(lookupAndMarginRequests()).toEqual([
      `${TYPE_LOOKUP_ENDPOINT}${SABRE_TYPE_ID}`,
      `${PROFIT_MARGIN_ENDPOINT}${SABRE_TYPE_ID}?runs=1&me=2&te=4`,
    ])
  })

  it.each(['projects', 'batch'] as const)('looks the T2 type of a margins link from the %s tab up, then analyzes it with ME 2 and TE 4', async (sourceTab) => {
    outcomes.respondTo(`${TYPE_LOOKUP_ENDPOINT}${SABRE_TYPE_ID}`, respondWith(SABRE))
    outcomes.respondTo(`${PROFIT_MARGIN_ENDPOINT}${SABRE_TYPE_ID}`, awaiting(deferred<ProfitMarginResult>()))
    await mountIndustryWithQuery({ tab: sourceTab })

    useIndustryStore().navigationIntent = { target: 'margins', typeId: SABRE_TYPE_ID }
    await flushPromises()

    expect(lookupAndMarginRequests()).toEqual([
      `${TYPE_LOOKUP_ENDPOINT}${SABRE_TYPE_ID}`,
      `${PROFIT_MARGIN_ENDPOINT}${SABRE_TYPE_ID}?runs=1&me=2&te=4`,
    ])
  })

  it('shows the lookup failure with a retry and analyzes nothing for an unknown type of the query', async () => {
    outcomes.respondTo(`${TYPE_LOOKUP_ENDPOINT}${ABSENT_TYPE_ID}`, rejectWith(new ApiError('Not Found', 404)))

    const wrapper = await mountIndustryWithQuery({ tab: 'margins', typeId: String(ABSENT_TYPE_ID) })

    const lookupError = wrapper.find('[data-testid="type-lookup-error"]')
    expect({
      requests: lookupAndMarginRequests(),
      lookupErrorNamesTheType: lookupError.exists() && lookupError.text().includes(String(ABSENT_TYPE_ID)),
      retryShown: lookupError.exists() && lookupError.findAll('button').some(button => button.text() === en.common.actions.retry),
    }).toEqual({
      requests: [`${TYPE_LOOKUP_ENDPOINT}${ABSENT_TYPE_ID}`],
      lookupErrorNamesTheType: true,
      retryShown: true,
    })
  })
})
