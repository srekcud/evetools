import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import MiningSiteDetail from '@/views/MiningSite/MiningSiteDetail.vue'
import { compression } from '../MiningOperation/miningOperationLedgerFixtures'
import {
  KERNITE,
  ORE_VALUES_PATH,
  SCANS_PATH,
  SCORDITE,
  SITE_ID,
  SITE_PATH,
  VELDSPAR,
  at,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteAfterThreeScans,
} from './miningSiteFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: SITE_ID }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PORPOISE_TYPE_ID = 42_244

function scans(): MiningSiteScan[] {
  return [
    scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000), presentType(SCORDITE, 5_000), presentType(KERNITE, 2_000)], { authorName: 'Aria Solberg' }),
    scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 25_000), presentType(SCORDITE, 5_000), presentType(KERNITE, 2_000)], { authorName: 'Brann Holt' }),
  ]
}

function porpoiseSite(): MiningSite {
  return siteAfterThreeScans({
    compression: compression({
      boostShipTypeId: PORPOISE_TYPE_ID,
      boostShipName: 'Porpoise',
      types: [
        { typeId: VELDSPAR, compressible: true, status: 'on_site' },
        { typeId: SCORDITE, compressible: false, status: 'no_hull_compressor' },
        { typeId: KERNITE, compressible: null, status: 'unknown_ore_kind' },
      ],
      gaps: { noHull: ['moon'], notMounted: ['ice'] },
    }),
  })
}

async function mountDetail(site: MiningSite) {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SITE_PATH) return site as never
    if (endpoint === SCANS_PATH) return scans() as never
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
  const i18n = i18nFor('en')
  const wrapper = mount(MiningSiteDetail, {
    global: {
      plugins: [i18n],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, LoadingSpinner: true, teleport: true, 'router-link': { template: '<a><slot /></a>' } },
    },
  })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

function compressionBadgeOf(wrapper: VueWrapper, typeId: number): string | null {
  const badge = wrapper.find(`[data-testid="type-row-${typeId}"] [data-testid="type-compression"]`)
  return badge.exists() ? badge.text() : null
}

function alertsAbout(wrapper: VueWrapper, phrase: string): string[] {
  return wrapper.findAll('[role="alert"]').map(alert => alert.text()).filter(text => text.includes(phrase))
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(new Date(at('20:40:00')))
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningSiteDetail compression', () => {
  it('badges each type of the last scan: on site, none, unknown', async () => {
    const { wrapper, t } = await mountDetail(porpoiseSite())

    expect([VELDSPAR, SCORDITE, KERNITE].map(typeId => compressionBadgeOf(wrapper, typeId))).toEqual([
      t('miningOperation.boostFit.badge.onSite'),
      t('miningOperation.boostFit.badge.none'),
      t('miningOperation.boostFit.badge.unknown'),
    ])
  })

  it('warns in amber about the kinds the hull cannot compress and the kinds not mounted, and says the value of the shares does not change', async () => {
    const { wrapper, t } = await mountDetail(porpoiseSite())

    const noHull = alertsAbout(wrapper, 'Porpoise')
    const notMounted = alertsAbout(wrapper, t('miningOperation.boostFit.kind.ice').toLocaleLowerCase('en'))
    expect({ noHull, notMounted, shares: wrapper.text().includes(t('miningOperation.boostFit.sharesUnchanged')) }).toEqual({
      noHull: [t('miningOperation.boostFit.noHullBanner', { ship: 'Porpoise', kinds: t('miningOperation.boostFit.kind.moon').toLocaleLowerCase('en') })],
      notMounted: [t('miningOperation.boostFit.notMountedBanner', { kinds: t('miningOperation.boostFit.kind.ice').toLocaleLowerCase('en') })],
      shares: true,
    })
  })

  it('shows neither badge nor banner without a boost ship or without a compression (private site)', async () => {
    const withoutShip = await mountDetail(siteAfterThreeScans({ compression: compression({ types: [{ typeId: VELDSPAR, compressible: null, status: 'no_boost_ship' }] }) }))
    const withoutShipResult = { badge: compressionBadgeOf(withoutShip.wrapper, VELDSPAR), banners: alertsAbout(withoutShip.wrapper, 'compress') }
    withoutShip.wrapper.unmount()
    const privateSite = await mountDetail(siteAfterThreeScans({ compression: null }))

    expect({ withoutShip: withoutShipResult, privateSite: { badge: compressionBadgeOf(privateSite.wrapper, VELDSPAR), shares: privateSite.wrapper.text().includes(privateSite.t('miningOperation.boostFit.sharesUnchanged')) } }).toEqual({
      withoutShip: { badge: null, banners: [] },
      privateSite: { badge: null, shares: false },
    })
  })
})
