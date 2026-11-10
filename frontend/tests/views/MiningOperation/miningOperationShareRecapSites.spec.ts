import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import { buildShareRecap, shareRecapLines } from '@/views/MiningOperation/miningOperationShareRecap'
import type { MiningOperation, MiningOperationLedger, MiningOperationValuation } from '@/stores/miningOperation'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS_PATH, OPERATION_O } from './miningOperationLedgerFixtures'
import { MEMBERS_WITH_CYRA, VALUATION_PATH } from './miningOperationValuationFixtures'
import { PAYMENTS_PATH } from './miningOperationPaymentFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'
import {
  PROPORTIONAL_RECAP_LINES,
  RECAP_BASIS,
  UNKNOWN_PILOT_LABEL,
  minedLedger,
  recapOperation,
  recapValuation,
  translator,
} from './miningOperationShareRecapFixtures'

const route = reactive<{ params: Record<string, string>; query: Record<string, string> }>({ params: { id: OPERATION_O }, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const t = translator()
const RECAP_TEXT = '[data-test="recap-text"]'
const FIGURES_LINE_INDEX = 2
const PROPORTIONAL_FIGURES_LINE = 'ISK/h: 512.75M/h · duration 4 h 00 · 3 participants'
const SITES_PATH = `/mining-sites?operationId=${OPERATION_O}`

function linesWithSites(sitesCount: number | null): string[] {
  const recap = buildShareRecap(recapOperation(), recapValuation(), minedLedger(), UNKNOWN_PILOT_LABEL, sitesCount)
  return shareRecapLines(recap, RECAP_BASIS, t)
}

function answer(operation: MiningOperation, valuation: MiningOperationValuation, operationLedger: MiningOperationLedger, sites: unknown[] | Error): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SITES_PATH) {
      if (sites instanceof Error) throw sites
      return sites as never
    }
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return MEMBERS_WITH_CYRA as never
    if (endpoint === LEDGER_PATH) return operationLedger as never
    if (endpoint === VALUATION_PATH) return valuation as never
    if (endpoint === PAYMENTS_PATH) return [] as never
    return [] as never
  })
}

async function mountDetail() {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockReturnValue(null)
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })],
      stubs: { MainLayout: { template: '<div><slot /></div>' }, teleport: true, RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

function site(id: string, status: 'active' | 'closed') {
  return { id, operationId: OPERATION_O, label: `Site ${id}`, status, createdAt: '2026-11-05T20:00:00+00:00', missingPriceTypeIds: [] }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('shareRecapLines, number of sites', () => {
  it('adds the number of sites after the participants in the figures line, singular for one site', () => {
    expect([linesWithSites(2)[FIGURES_LINE_INDEX], linesWithSites(1)[FIGURES_LINE_INDEX]]).toEqual([
      `${PROPORTIONAL_FIGURES_LINE} · 2 sites`,
      `${PROPORTIONAL_FIGURES_LINE} · 1 site`,
    ])
  })

  it('leaves the text unchanged when the operation has no site or the number is not given', () => {
    expect([linesWithSites(0), linesWithSites(null)]).toEqual([PROPORTIONAL_RECAP_LINES, PROPORTIONAL_RECAP_LINES])
  })

  it('keeps the site count away from the other lines and from any site name', () => {
    expect(linesWithSites(2).filter((_, index) => index !== FIGURES_LINE_INDEX)).toEqual(PROPORTIONAL_RECAP_LINES.filter((_, index) => index !== FIGURES_LINE_INDEX))
  })
})

describe('MiningOperationDetail, summary to share, number of sites', () => {
  it('counts every site of the operation, active or closed, without naming any', async () => {
    answer(recapOperation(), recapValuation({ frozen: true }), minedLedger(), [site('a', 'active'), site('b', 'closed'), site('c', 'closed')])

    const wrapper = await mountDetail()

    const text = wrapper.find(RECAP_TEXT).element.textContent ?? ''
    expect(text).toContain(`${PROPORTIONAL_FIGURES_LINE} · 3 sites`)
    expect(text).not.toContain('Site a')
  })

  it('leaves the sites out of the summary when the sites cannot be loaded', async () => {
    answer(recapOperation(), recapValuation({ frozen: true }), minedLedger(), new Error('sites unavailable'))

    const wrapper = await mountDetail()

    expect(wrapper.find(RECAP_TEXT).element.textContent).toBe(PROPORTIONAL_RECAP_LINES.join('\n'))
  })
})
