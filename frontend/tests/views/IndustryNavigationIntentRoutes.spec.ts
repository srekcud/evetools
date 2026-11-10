import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import type { LocationQuery } from 'vue-router'
import en from '@/i18n/locales/en.json'
import Industry from '@/views/Industry.vue'
import { useAdminStore } from '@/stores/admin'
import { useIndustryStore } from '@/stores/industry'
import type { NavigationIntent } from '@/stores/industry/types'
import { landingOf, type Landing } from './industryPages'

const route = reactive<{ query: LocationQuery }>({ query: {} })
const routerPush = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: routerPush })) as never,
}))

const RAVEN_TYPE_ID = 587
const ORACLE_TYPE_ID = 4302
const MUNINN_TYPE_ID = 12015

const stubbedChildren = {
  MainLayout: { template: '<div><slot /></div>' },
  IndustryModuleTabs: true,
  teleport: true,
  ErrorBanner: true,
  BlacklistConfig: true,
  StructureConfig: true,
  SkillsConfig: true,
  FeesConfig: true,
  IndustryDashboard: true,
  IndustryWorkshop: true,
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  routerPush.mockClear()
  route.query = {}
  useIndustryStore().navigationIntent = null
  vi.spyOn(useAdminStore(), 'checkAccess').mockResolvedValue(false)
})

describe('Industry navigation intents', () => {
  it.each<[string, NavigationIntent, Landing]>([
    ['margins with a type', { target: 'margins', typeId: RAVEN_TYPE_ID }, { path: '/industry/analysis', query: { tab: 'margins', typeId: String(RAVEN_TYPE_ID) } }],
    ['buy vs build with a type', { target: 'buy-vs-build', typeId: ORACLE_TYPE_ID }, { path: '/industry/analysis', query: { tab: 'buy-vs-build', typeId: String(ORACLE_TYPE_ID) } }],
    ['pivot with a type', { target: 'pivot', typeId: MUNINN_TYPE_ID }, { path: '/industry/analysis', query: { tab: 'pivot', typeId: String(MUNINN_TYPE_ID) } }],
    ['batch without a type', { target: 'batch' }, { path: '/industry/analysis', query: { tab: 'batch' } }],
    ['slots', { target: 'slots' }, { path: '/industry/production', query: { tab: 'slots' } }],
    ['stockpile', { target: 'stockpile' }, { path: '/industry/production', query: { tab: 'stockpile' } }],
  ])('sends the %s intent to its page and sub-tab, then consumes it', async (_case, intent, expectedLanding) => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    mount(Industry, { props: { page: 'workshop' }, global: { plugins: [i18n], stubs: stubbedChildren } })
    await flushPromises()

    useIndustryStore().navigationIntent = intent
    await flushPromises()

    expect(routerPush).toHaveBeenCalledTimes(1)
    expect(landingOf(routerPush.mock.calls[0][0])).toEqual(expectedLanding)
    expect(useIndustryStore().navigationIntent).toBeNull()
  })
})
