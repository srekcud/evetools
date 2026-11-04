import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import type { MiningSite } from '@/stores/miningSite'
import MiningSiteHistory from '@/components/mining-site/MiningSiteHistory.vue'
import { ALDRANETTE, JASPET, LONG_SITE_LABEL, SITES_PATH, at, found, i18nFor, siteOf } from '../../views/MiningSite/miningSiteFixtures'

const push = vi.fn()

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => ({ params: {}, query: {} })) as never,
  useRouter: (() => ({ push, replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const UNKNOWN_DASH = '–'
const BELT_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e61'
const ANOMALY_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e62'
const JASPET_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e63'

const BELT = siteOf({
  id: BELT_ID,
  label: 'Belt Aldranette',
  firstScanAt: at('20:00:00'),
  lastScanAt: at('20:20:00'),
  observedSeconds: 1_200,
  firstVolumeM3: 3_750,
  firstValue: 575_000,
  averageTrendRate: 600,
})
const ANOMALY_WITHOUT_PRICE = siteOf({
  id: ANOMALY_ID,
  label: LONG_SITE_LABEL,
  solarSystemId: null,
  solarSystemName: null,
  status: 'closed',
  firstScanAt: at('18:00:00'),
  lastScanAt: at('18:40:00'),
  observedSeconds: 2_400,
  firstVolumeM3: 8_000,
  averageTrendRate: 450,
})
const JASPET_WITHOUT_SCAN = siteOf({ id: JASPET_ID, label: 'Belt Jaspet', solarSystemId: JASPET, solarSystemName: 'Jaspet' })
const NEWEST_FIRST = [BELT, ANOMALY_WITHOUT_PRICE, JASPET_WITHOUT_SCAN]

function answerWithSites(sites: MiningSite[]): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (endpoint === SITES_PATH) return sites as never
    if (endpoint === `${SITES_PATH}/${BELT_ID}/close` && options?.method === 'POST') return { ...BELT, status: 'closed' } as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountHistory() {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningSiteHistory, {
    global: { plugins: [i18n], stubs: { teleport: true, LoadingSpinner: true, 'router-link': { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  return { wrapper, t: i18n.global.t }
}

function rowOf(wrapper: VueWrapper, siteId: string) {
  return wrapper.find(`[data-testid="site-row-${siteId}"]`)
}

function cellsOf(wrapper: VueWrapper, siteId: string): string[] {
  const row = rowOf(wrapper, siteId)
  return row.exists() ? row.findAll('td').map(cell => cell.text()) : []
}

function shownSiteIds(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="site-row-"]').map(row => String(row.attributes('data-testid')).replace('site-row-', ''))
}

beforeEach(() => {
  setActivePinia(createPinia())
  push.mockReset()
  vi.mocked(apiRequest).mockReset()
})

enableAutoUnmount(afterEach)

describe('MiningSiteHistory', () => {
  it('lists the sites newest first with their summary, unknown values apart from 0 and dashes without scan, and filters them by solar system on the client', async () => {
    answerWithSites(NEWEST_FIRST)

    const { wrapper, t } = await mountHistory()
    const everySite = shownSiteIds(wrapper)
    const beltCells = cellsOf(wrapper, BELT_ID).slice(0, 8)
    const anomalyValue = cellsOf(wrapper, ANOMALY_ID)[6]
    const jaspetScanColumns = cellsOf(wrapper, JASPET_ID).slice(2, 8)
    const longLabel = wrapper.find(`[title="${LONG_SITE_LABEL}"]`)
    const longLabelShown = longLabel.exists()
    const longLabelTruncated = longLabel.exists() && longLabel.classes().includes('truncate')
    const filter = found(wrapper, '[data-testid="system-filter"]')
    const filterOptions = filter.findAll('option').map(option => option.element.value)
    await filter.setValue(String(ALDRANETTE))
    const filtered = shownSiteIds(wrapper)
    await found(wrapper, `[data-testid="site-row-${BELT_ID}"]`).trigger('click')

    expect({
      everySite,
      beltCells,
      anomalyValue,
      jaspetScanColumns,
      longLabelShown,
      longLabelTruncated,
      filterOptions,
      filtered,
      requests: vi.mocked(apiRequest).mock.calls.map(call => call[0]),
      opened: push.mock.calls.map(call => call[0]),
    }).toEqual({
      everySite: [BELT_ID, ANOMALY_ID, JASPET_ID],
      beltCells: [
        expect.stringContaining('Belt Aldranette'),
        expect.stringContaining('Aldranette'),
        expect.stringContaining('20:00'),
        expect.stringContaining('20:20'),
        expect.stringContaining('20 min'),
        expect.stringContaining('3,750'),
        expect.stringContaining('575,000'),
        expect.stringContaining('600'),
      ],
      anomalyValue: expect.stringContaining(t('miningSite.value.unknown')),
      jaspetScanColumns: [UNKNOWN_DASH, UNKNOWN_DASH, UNKNOWN_DASH, UNKNOWN_DASH, UNKNOWN_DASH, UNKNOWN_DASH],
      longLabelShown: true,
      longLabelTruncated: true,
      filterOptions: ['', String(ALDRANETTE), String(JASPET)],
      filtered: [BELT_ID],
      requests: [SITES_PATH],
      opened: [expect.objectContaining({ name: 'mining-site-detail', params: { id: BELT_ID } })],
    })
  })

  it('closes an active site only after a confirmation and offers no closing on a closed site', async () => {
    answerWithSites(NEWEST_FIRST)
    const { wrapper, t } = await mountHistory()
    const closedSiteOffersClosing = wrapper.find(`[data-testid="site-close-${ANOMALY_ID}"]`).exists()

    await found(wrapper, `[data-testid="site-close-${BELT_ID}"]`).trigger('click')
    const closeCallsBeforeConfirmation = vi.mocked(apiRequest).mock.calls.filter(call => call[0] === `${SITES_PATH}/${BELT_ID}/close`).length
    const confirmation = wrapper.findAll('button').filter(button => [t('common.actions.confirm'), t('miningSite.close.confirm')].includes(button.text().trim())).pop()
    expect(confirmation, 'confirmation button').toBeDefined()
    await confirmation?.trigger('click')
    await flushPromises()

    expect({
      closedSiteOffersClosing,
      closeCallsBeforeConfirmation,
      closeCalls: vi.mocked(apiRequest).mock.calls.filter(call => call[0] === `${SITES_PATH}/${BELT_ID}/close`).map(call => call[1]?.method),
      statusAfter: cellsOf(wrapper, BELT_ID)[8],
    }).toEqual({
      closedSiteOffersClosing: false,
      closeCallsBeforeConfirmation: 0,
      closeCalls: ['POST'],
      statusAfter: expect.stringContaining(t('miningSite.status.closed')),
    })
  })

  it('says that there is no site yet with a button to estimate one, and shows an alert when the sites cannot be loaded', async () => {
    answerWithSites([])
    const empty = await mountHistory()
    const emptyMessage = empty.wrapper.text()
    const estimate = found(empty.wrapper, '[data-testid="site-estimate"]')
    await estimate.trigger('click')
    const creationOpened = empty.wrapper.find('[data-testid="create-form"]').exists()
    empty.wrapper.unmount()
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockRejectedValue(new Error('Service unavailable'))
    const failing = await mountHistory()

    expect({
      emptyMessage,
      estimateLabel: estimate.text(),
      creationOpened,
      alert: failing.wrapper.find('[role="alert"]').exists() ? failing.wrapper.find('[role="alert"]').text() : null,
    }).toEqual({
      emptyMessage: expect.stringContaining(empty.t('miningSite.history.empty')),
      estimateLabel: empty.t('miningSite.history.estimate'),
      creationOpened: true,
      alert: expect.stringContaining('Service unavailable'),
    })
  })
})
