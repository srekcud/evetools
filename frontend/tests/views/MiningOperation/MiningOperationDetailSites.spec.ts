import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningOperationDetail from '@/views/MiningOperation/MiningOperationDetail.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type MiningOperationEvent } from '@/stores/sync'
import type { MiningOperation } from '@/stores/miningOperation'
import type { MiningSite } from '@/stores/miningSite'
import { DETAIL_PATH, LEDGER_PATH, MEMBERS, MEMBERS_PATH, OPERATION_O, OPERATION_P2, ledger, spodumainOperation, startedSpodumainOperation } from './miningOperationLedgerFixtures'
import { MEMBERS_WITH_CYRA, VALUATION_PATH, unmeasuredValuation } from './miningOperationValuationFixtures'
import { PAYMENTS_PATH, g1Payments, g1Valuation, settledG1Operation } from './miningOperationPaymentFixtures'
import { answerWithoutFleetStorage } from './miningOperationFleetStorageFixtures'
import { OTHER_SITE_ID, SITE_ID, at, siteOf } from '../MiningSite/miningSiteFixtures'

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

const OPERATION_SITES_PATH = `/mining-sites?operationId=${OPERATION_O}`
const CHARLIE_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e52'

const events = reactive<Record<string, MiningOperationEvent | null>>({})
const served: { sites: MiningSite[] } = { sites: [] }

function belt(): MiningSite {
  return siteOf({
    id: SITE_ID,
    operationId: OPERATION_O,
    label: 'Belt Aldranette',
    status: 'active',
    firstScanAt: at('20:00:00'),
    lastScanAt: at('20:20:00'),
    lastScanNumber: 3,
    lastScanAuthorName: null,
  })
}

function anomalyWithoutScan(): MiningSite {
  return siteOf({ id: OTHER_SITE_ID, operationId: OPERATION_O, label: 'Anomalie Spodumain', solarSystemId: null, solarSystemName: null, status: 'closed' })
}

function charlieBelt(): MiningSite {
  return siteOf({
    id: CHARLIE_SITE_ID,
    operationId: OPERATION_O,
    label: 'Belt Jaspet',
    solarSystemName: 'Jaspet',
    status: 'active',
    firstScanAt: at('19:50:00'),
    lastScanAt: at('20:00:30'),
    lastScanNumber: 7,
    lastScanAuthorName: 'Charlie',
  })
}

function answer(operation: MiningOperation): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === OPERATION_SITES_PATH) return served.sites as never
    const fleetStorageAnswer = answerWithoutFleetStorage(endpoint)
    if (fleetStorageAnswer !== undefined) return fleetStorageAnswer as never
    if (endpoint === DETAIL_PATH) return operation as never
    if (endpoint === MEMBERS_PATH) return (operation.status === 'settled' ? MEMBERS_WITH_CYRA : MEMBERS) as never
    if (endpoint === LEDGER_PATH) return ledger({ status: operation.status, endedAt: operation.endedAt }) as never
    if (endpoint === VALUATION_PATH) return (operation.status === 'settled' ? g1Valuation({ status: 'settled' }) : unmeasuredValuation('in_progress')) as never
    if (endpoint === PAYMENTS_PATH) return (operation.status === 'settled' ? g1Payments([0, 1, 2]) : []) as never
    return [] as never
  })
}

async function mountDetail() {
  vi.spyOn(useSyncStore(), 'getMiningOperationEvent').mockImplementation((operationId: string) => events[operationId] ?? null)
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { en, fr } })
  const wrapper = mount(MiningOperationDetail, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        teleport: true,
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

async function receive(operationId: string, action: string): Promise<void> {
  events[operationId] = { action, data: { siteId: SITE_ID }, timestamp: at('20:24:30') }
  await nextTick()
  await flushPromises()
}

function callsTo(path: string): number {
  return vi.mocked(apiRequest).mock.calls.filter(call => call[0] === path).length
}

function cardTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid="operation-site-card"]').map(card => card.text().replace(/\s+/g, ' ').trim())
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(at('20:24:30')))
  setActivePinia(createPinia())
  Object.keys(events).forEach(operationId => delete events[operationId])
  served.sites = []
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningOperationDetail, sites of the operation', () => {
  it('lists the sites of the operation for an accepted participant with their last scan, an unknown author when the account is gone, and a link to each site', async () => {
    served.sites = [belt(), anomalyWithoutScan(), charlieBelt()]
    answer(startedSpodumainOperation())

    const { wrapper, t } = await mountDetail()

    const links = wrapper.findAll('[data-testid="operation-site-card"] a, a[data-testid="operation-site-card"]').map(link => JSON.parse(link.attributes('data-to') ?? 'null'))
    expect({
      sitesRequests: callsTo(OPERATION_SITES_PATH),
      cards: cardTexts(wrapper),
      linkedSiteIds: links.map(target => target?.params?.id),
      linkedRouteNames: links.map(target => target?.name),
    }).toEqual({
      sitesRequests: 1,
      cards: [
        expect.stringMatching(/Belt Aldranette.*Aldranette.*Actif.*Dernier scan #3 par auteur inconnu, il y a 4 min/),
        expect.stringMatching(/^Anomalie Spodumain.*Clôturé.*aucun scan/i),
        expect.stringMatching(/Belt Jaspet.*Jaspet.*Actif.*Dernier scan #7 par Charlie, il y a 24 min/),
      ],
      linkedSiteIds: [SITE_ID, OTHER_SITE_ID, CHARLIE_SITE_ID],
      linkedRouteNames: ['mining-site-detail', 'mining-site-detail', 'mining-site-detail'],
    })
    expect(cardTexts(wrapper)[1]).not.toContain(t('miningSite.create.systemNone'))
  })

  it('offers a new site while the operation is not archived and only the empty message once it is settled', async () => {
    answer(startedSpodumainOperation())
    const running = await mountDetail()
    const runningState = {
      newSite: running.wrapper.find('[data-testid="operation-site-create"]').exists(),
      empty: running.wrapper.find('[data-testid="operation-sites-empty"]').exists() && running.wrapper.text().includes(running.t('miningOperationSites.empty')),
    }
    running.wrapper.unmount()
    answer(settledG1Operation())

    const settled = await mountDetail()

    expect({
      runningState,
      settledState: {
        newSite: settled.wrapper.find('[data-testid="operation-site-create"]').exists(),
        empty: settled.wrapper.find('[data-testid="operation-sites-empty"]').exists() && settled.wrapper.text().includes(settled.t('miningOperationSites.empty')),
      },
    }).toEqual({
      runningState: { newSite: true, empty: true },
      settledState: { newSite: false, empty: true },
    })
  })

  it('reloads the sites on a scan recorded on the displayed operation and ignores the one of another operation', async () => {
    served.sites = [belt()]
    answer(startedSpodumainOperation())
    const { wrapper } = await mountDetail()
    vi.mocked(apiRequest).mockClear()
    served.sites = [siteOf({ ...belt(), lastScanNumber: 4, lastScanAuthorName: 'Charlie', lastScanAt: at('20:23:30') })]

    await receive(OPERATION_O, 'site_scan_recorded')
    const afterOwnEvent = { requests: callsTo(OPERATION_SITES_PATH), cards: cardTexts(wrapper) }
    await receive(OPERATION_P2, 'site_scan_recorded')

    expect({ afterOwnEvent, afterOtherOperationEvent: callsTo(OPERATION_SITES_PATH) }).toEqual({
      afterOwnEvent: { requests: 1, cards: [expect.stringContaining('Dernier scan #4 par Charlie, il y a 1 min')] },
      afterOtherOperationEvent: 1,
    })
  })

  it('reloads the sites when the next site is started on the displayed operation and ignores the one of another operation', async () => {
    served.sites = [belt()]
    answer(startedSpodumainOperation())
    const { wrapper } = await mountDetail()
    vi.mocked(apiRequest).mockClear()
    served.sites = [siteOf({ ...belt(), status: 'closed', closedAt: at('20:24:00') }), siteOf({ id: CHARLIE_SITE_ID, operationId: OPERATION_O, label: 'Belt Jaspet', status: 'active' })]

    await receive(OPERATION_O, 'site_next_started')
    const afterOwnEvent = { requests: callsTo(OPERATION_SITES_PATH), cards: cardTexts(wrapper).map(card => card.includes('Belt Jaspet')) }
    await receive(OPERATION_P2, 'site_next_started')

    expect({ afterOwnEvent, afterOtherOperationEvent: callsTo(OPERATION_SITES_PATH) }).toEqual({
      afterOwnEvent: { requests: 1, cards: [false, true] },
      afterOtherOperationEvent: 1,
    })
  })

  it('loads the sites for an accepted participant only, not for a participant waiting for acceptance', async () => {
    answer(startedSpodumainOperation())
    const accepted = await mountDetail()
    const acceptedRequests = callsTo(OPERATION_SITES_PATH)
    accepted.wrapper.unmount()
    vi.mocked(apiRequest).mockClear()
    answer(spodumainOperation({ myStatus: 'pending', myRole: 'member' }))

    const pending = await mountDetail()

    expect({
      acceptedRequests,
      pendingRequests: callsTo(OPERATION_SITES_PATH),
      pendingSection: pending.wrapper.find('[data-testid="operation-sites"]').exists(),
    }).toEqual({ acceptedRequests: 1, pendingRequests: 0, pendingSection: false })
  })
})
