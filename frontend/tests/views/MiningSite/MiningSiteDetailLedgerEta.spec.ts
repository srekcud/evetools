import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import { formatEveClock } from '@/views/MiningOperation/miningOperationTime'
import MiningSiteDetail from '@/views/MiningSite/MiningSiteDetail.vue'
import {
  OPERATION_ID,
  ORE_VALUES_PATH,
  SCANS_PATH,
  SITE_ID,
  SITE_PATH,
  VELDSPAR,
  at,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteOf,
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

const LEDGER_ETA = at('22:40:00')

function oneScan(): MiningSiteScan[] {
  return [scanOf(1, at('20:10:00'), [presentType(VELDSPAR, 60_000)], { authorName: 'Aria Solberg' })]
}

function twoScans(): MiningSiteScan[] {
  return [...oneScan(), scanOf(2, at('20:20:00'), [presentType(VELDSPAR, 50_000)], { authorName: 'Brann Holt' })]
}

function operationSite(overrides: Partial<MiningSite>): MiningSite {
  return siteOf({ operationId: OPERATION_ID, firstScanAt: at('20:10:00'), lastScanAt: at('20:10:00'), lastScanNumber: 1, ...overrides })
}

async function mountDetail(site: MiningSite, scans: MiningSiteScan[]) {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SITE_PATH) return site as never
    if (endpoint === SCANS_PATH) return scans as never
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

describe('MiningSiteDetail ledger ETA', () => {
  it('shows the ledger ETA of a single scan site with the approximate badge and the note about the fleet ledger', async () => {
    const { wrapper, t } = await mountDetail(operationSite({ ledgerEta: LEDGER_ETA }), oneScan())

    expect({
      eta: wrapper.find('[data-testid="site-ledger-eta"]').text(),
      badge: wrapper.find('[data-testid="site-ledger-eta-badge"]').text(),
      note: wrapper.find('[data-testid="site-ledger-eta-note"]').text(),
    }).toEqual({
      eta: expect.stringContaining(formatEveClock(LEDGER_ETA)),
      badge: t('miningSite.ledgerEta.badge'),
      note: t('miningSite.ledgerEta.note'),
    })
  })

  it('keeps the second scan hint and says why the ledger gives no ETA', async () => {
    const { wrapper, t } = await mountDetail(operationSite({ ledgerEtaUnknownReason: 'nothing_mined' }), oneScan())

    expect({
      time: wrapper.find('[data-testid="site-ledger-eta"]').exists(),
      reason: wrapper.find('[data-testid="site-ledger-eta-reason"]').text(),
      hint: wrapper.find('[data-testid="site-single-scan-hint"]').text(),
    }).toEqual({
      time: false,
      reason: t('miningSite.ledgerEta.reason.nothing_mined'),
      hint: t('miningSite.single.hint'),
    })
  })

  it('shows nothing about the ledger once the site has a second scan', async () => {
    const { wrapper } = await mountDetail(operationSite({ lastScanNumber: 2, lastScanAt: at('20:20:00') }), twoScans())

    expect(['site-ledger-eta', 'site-ledger-eta-reason'].map(testId => wrapper.find(`[data-testid="${testId}"]`).exists())).toEqual([false, false])
  })
})
