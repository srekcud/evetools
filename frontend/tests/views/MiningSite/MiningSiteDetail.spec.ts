import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { ApiError, apiRequest } from '@/services/api'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import MiningSiteDetail from '@/views/MiningSite/MiningSiteDetail.vue'
import {
  KERNITE,
  LONG_ORE_NAME,
  ORE_VALUES_PATH,
  PLAGIOCLASE,
  SCANS_PATH,
  SCORDITE,
  SITE_ID,
  SITE_PATH,
  VELDSPAR,
  at,
  depletedType,
  i18nFor,
  oreValues,
  presentType,
  scanOf,
  siteAfterThreeScans,
  siteOf,
  veldsparAndScorditeScans,
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

const UNKNOWN_DASH = '–'

function answer(site: MiningSite, scans: MiningSiteScan[], oreValueTable = oreValues()): void {
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SITE_PATH) return site as never
    if (endpoint === SCANS_PATH) return scans as never
    if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValueTable as never
    throw new Error(`Unexpected request ${endpoint}`)
  })
}

async function mountDetail() {
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

function setNow(clock: string): void {
  vi.setSystemTime(new Date(at(clock)))
}

function textOf(wrapper: VueWrapper, testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

function attributeOf(wrapper: VueWrapper, selector: string, name: string): string | null {
  const element = wrapper.find(selector)
  return element.exists() ? element.attributes(name) ?? null : null
}

function rowOf(wrapper: VueWrapper, typeId: number) {
  return wrapper.find(`[data-testid="type-row-${typeId}"]`)
}

function rowTextOf(wrapper: VueWrapper, typeId: number): string | null {
  const row = rowOf(wrapper, typeId)
  return row.exists() ? row.text() : null
}

function cellsOf(wrapper: VueWrapper, typeId: number): string[] {
  const row = rowOf(wrapper, typeId)
  return row.exists() ? row.findAll('td').map(cell => cell.text()) : []
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  setNow('20:40:00')
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

enableAutoUnmount(afterEach)

describe('MiningSiteDetail', () => {
  it('shows the header, the trend ETA with its colour label, the type that falls last, the ring, the evolution of the ETAs and the legend of the curve', async () => {
    answer(siteAfterThreeScans(), veldsparAndScorditeScans())

    const { wrapper, t } = await mountDetail()

    expect({
      label: textOf(wrapper, 'site-label'),
      labelTitle: attributeOf(wrapper, '[data-testid="site-label"]', 'title'),
      system: textOf(wrapper, 'site-system'),
      status: textOf(wrapper, 'site-status'),
      scanCount: textOf(wrapper, 'site-scan-count'),
      firstScan: textOf(wrapper, 'site-first-scan'),
      etaTrend: textOf(wrapper, 'site-eta-trend'),
      etaRecent: textOf(wrapper, 'site-eta-recent'),
      lastType: textOf(wrapper, 'site-last-type'),
      tier: textOf(wrapper, 'site-tier'),
      countdown: textOf(wrapper, 'site-countdown'),
      ring: textOf(wrapper, 'site-ring'),
      evolutionNumbers: wrapper.findAll('[data-testid="evolution-row"]').map(row => row.attributes('data-scan-number')),
      newestEvolution: wrapper.findAll('[data-testid="evolution-row"]')[0]?.text() ?? null,
      oldestEvolution: wrapper.findAll('[data-testid="evolution-row"]')[2]?.text() ?? null,
      curveAlternative: attributeOf(wrapper, '[role="img"]', 'aria-label'),
      legend: textOf(wrapper, 'curve-legend'),
    }).toEqual({
      label: 'Belt Aldranette',
      labelTitle: 'Belt Aldranette',
      system: 'Aldranette',
      status: t('miningSite.status.active'),
      scanCount: expect.stringContaining('3'),
      firstScan: expect.stringContaining('20:00'),
      etaTrend: expect.stringContaining('20:50'),
      etaRecent: expect.stringContaining('20:45'),
      lastType: expect.stringContaining('Veldspar'),
      tier: expect.stringContaining(t('miningSite.tier.orange')),
      countdown: expect.stringContaining('10:33'),
      ring: expect.stringContaining('60'),
      evolutionNumbers: ['3', '2', '1'],
      newestEvolution: expect.stringMatching(/20:50.*20:45|20:45.*20:50/),
      oldestEvolution: expect.stringContaining(t('miningSite.eta.unknown')),
      curveAlternative: expect.stringMatching(/\S/),
      legend: expect.stringMatching(/Veldspar[\s\S]*Scordite/),
    })
  })

  it('counts down every second to the trend ETA, changes colour when the remaining time crosses 5 minutes and releases its timer when it leaves', async () => {
    setNow('20:45:31')
    answer(siteAfterThreeScans(), veldsparAndScorditeScans())
    const { wrapper, t } = await mountDetail()
    const before = { countdown: textOf(wrapper, 'site-countdown'), tier: textOf(wrapper, 'site-tier') }

    vi.advanceTimersByTime(1_000)
    await flushPromises()
    const afterOneSecond = textOf(wrapper, 'site-countdown')
    vi.advanceTimersByTime(2_000)
    await flushPromises()
    const afterThreeSeconds = { countdown: textOf(wrapper, 'site-countdown'), tier: textOf(wrapper, 'site-tier') }
    wrapper.unmount()

    expect({ before, afterOneSecond, afterThreeSeconds, timersLeft: vi.getTimerCount() }).toEqual({
      before: { countdown: expect.stringContaining('05:02'), tier: expect.stringContaining(t('miningSite.tier.orange')) },
      afterOneSecond: expect.stringContaining('05:01'),
      afterThreeSeconds: { countdown: expect.stringContaining('04:59'), tier: expect.stringContaining(t('miningSite.tier.red')) },
      timersLeft: 0,
    })
  })

  it('says that the ETA is unknown and asks for a 2nd scan after a single scan, with the remaining quantities, an unknown value and the types without price named', async () => {
    const singleScan = scanOf(1, at('20:20:00'), [
      presentType(VELDSPAR, 60_000, { etaUnknownReason: 'single_scan' }),
      presentType(SCORDITE, 8_000, { volumeM3: 1_200, unitPrice: 25, remainingValue: 200_000, etaUnknownReason: 'single_scan' }),
    ], { authorName: 'Aria Solberg', missingPriceTypeIds: [VELDSPAR] })
    answer(siteOf({ firstScanAt: at('20:20:00'), lastScanAt: at('20:20:00'), observedSeconds: 0, lastScanNumber: 1, lastScanAuthorName: 'Aria Solberg', missingPriceTypeIds: [VELDSPAR] }), [singleScan])

    const { wrapper, t } = await mountDetail()

    expect({
      etaTrend: textOf(wrapper, 'site-eta-trend'),
      hint: textOf(wrapper, 'site-single-scan-hint'),
      countdown: textOf(wrapper, 'site-countdown'),
      tier: textOf(wrapper, 'site-tier'),
      remainingValue: textOf(wrapper, 'site-remaining-value'),
      missingPrices: textOf(wrapper, 'site-missing-prices'),
      ring: textOf(wrapper, 'site-ring'),
      veldsparRemaining: cellsOf(wrapper, VELDSPAR)[1],
      veldsparValue: cellsOf(wrapper, VELDSPAR)[6],
      scorditeValue: cellsOf(wrapper, SCORDITE)[6],
      ratesAndEtasWithoutNumber: [VELDSPAR, SCORDITE].map(typeId => cellsOf(wrapper, typeId).slice(2, 6).every(cell => !/\d/.test(cell))),
      cubicMetresDisabled: attributeOf(wrapper, '[data-testid="curve-unit-m3"]', 'disabled') !== null,
      unitsDisabled: attributeOf(wrapper, '[data-testid="curve-unit-units"]', 'disabled') !== null,
    }).toEqual({
      etaTrend: expect.stringContaining(t('miningSite.eta.unknown')),
      hint: expect.stringContaining(t('miningSite.single.hint')),
      countdown: null,
      tier: expect.stringContaining(t('miningSite.tier.unknown')),
      remainingValue: expect.stringContaining(t('miningSite.value.unknown')),
      missingPrices: expect.stringContaining(t('miningSite.value.missingPrices', { types: 'Veldspar' })),
      ring: expect.stringContaining(UNKNOWN_DASH),
      veldsparRemaining: expect.stringContaining('60,000'),
      veldsparValue: expect.stringContaining(t('miningSite.value.unknown')),
      scorditeValue: expect.stringContaining('200,000'),
      ratesAndEtasWithoutNumber: [true, true],
      cubicMetresDisabled: true,
      unitsDisabled: false,
    })
  })

  it('lists each type of the last scan with its rates, its ETAs, its value and its badges, with a long ore name truncated under a tooltip', async () => {
    const scans = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 30_000), presentType(SCORDITE, 5_000), presentType(KERNITE, 2_000)]),
      scanOf(2, at('20:10:00'), [presentType(VELDSPAR, 29_500), presentType(SCORDITE, 5_000), presentType(KERNITE, 1_500)]),
      scanOf(3, at('20:20:00'), [
        presentType(VELDSPAR, 18_000, { volumeM3: 1_800, unitPrice: 15, remainingValue: 270_000, recentRate: 1_150, recentEta: at('20:35:39'), trendRate: 600, trendEta: at('20:53:03'), maxDeviationUnits: 3_666.67, maxDeviationPercent: 12.22 }),
        presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25, remainingValue: 125_000, etaUnknownReason: 'not_depleting' }),
        presentType(PLAGIOCLASE, 8_000, { volumeM3: 2_800, unitPrice: 30, remainingValue: 240_000, etaUnknownReason: 'single_scan' }),
        depletedType(KERNITE, at('20:10:00'), at('20:20:00')),
      ]),
    ]
    answer(siteAfterThreeScans({ recentEta: at('20:35:39'), trendEta: at('20:53:03') }), scans, oreValues(LONG_ORE_NAME))

    const { wrapper, t } = await mountDetail()

    const longName = wrapper.find(`[title="${LONG_ORE_NAME}"]`)
    expect({
      veldsparCells: cellsOf(wrapper, VELDSPAR).slice(1),
      veldsparBadges: rowTextOf(wrapper, VELDSPAR),
      scorditeBadges: rowTextOf(wrapper, SCORDITE),
      plagioclase: rowTextOf(wrapper, PLAGIOCLASE),
      kernite: rowTextOf(wrapper, KERNITE),
      longNameShown: longName.exists(),
      longNameTruncated: longName.exists() && longName.classes().includes('truncate'),
    }).toEqual({
      veldsparCells: [
        expect.stringContaining('18,000'),
        expect.stringContaining('1,150'),
        expect.stringContaining('20:35'),
        expect.stringContaining('600'),
        expect.stringContaining('20:53'),
        expect.stringContaining('270,000'),
      ],
      veldsparBadges: expect.stringContaining(t('miningSite.badge.irregular')),
      scorditeBadges: expect.stringContaining(t('miningSite.badge.notMined')),
      plagioclase: expect.stringContaining(t('miningSite.badge.singleScan')),
      kernite: expect.stringContaining(t('miningSite.badge.depleted', { from: '20:10', to: '20:20' })),
      longNameShown: true,
      longNameTruncated: true,
    })
    expect(rowTextOf(wrapper, PLAGIOCLASE)).toContain(t('common.unknownTypeName', { typeId: PLAGIOCLASE }))
  })

  it('highlights the most profitable ore per m3 with its two ETAs and nothing else', async () => {
    const scans = [
      scanOf(1, at('20:00:00'), [presentType(VELDSPAR, 20_000), presentType(SCORDITE, 6_000)]),
      scanOf(2, at('20:20:00'), [
        presentType(VELDSPAR, 18_000, { volumeM3: 1_800, unitPrice: 15, remainingValue: 270_000, recentRate: 700, recentEta: at('20:45:43'), trendRate: 600, trendEta: at('20:50:33') }),
        presentType(SCORDITE, 5_000, { volumeM3: 750, unitPrice: 25, remainingValue: 125_000, recentRate: 50, recentEta: at('21:10:00'), trendRate: 40, trendEta: at('21:25:00') }),
      ]),
    ]
    answer(siteAfterThreeScans({ trendEta: at('21:25:00'), recentEta: at('21:10:00'), lastScanNumber: 2 }), scans)

    const { wrapper } = await mountDetail()

    expect(textOf(wrapper, 'best-ore')).toEqual(expect.stringMatching(/^(?=[\s\S]*Scordite)(?=[\s\S]*21:10)(?=[\s\S]*21:25)(?![\s\S]*Veldspar)/))
  })

  it('reminds to re-scan from the same position when the last scan is older than the delay, with its author, and says nothing before', async () => {
    answer(siteAfterThreeScans(), veldsparAndScorditeScans())
    setNow('20:31:00')
    const late = await mountDetail()
    const lateReminder = textOf(late.wrapper, 'site-rescan')
    late.wrapper.unmount()
    setNow('20:29:00')
    const early = await mountDetail()
    const earlyReminder = textOf(early.wrapper, 'site-rescan')
    early.wrapper.unmount()
    localStorage.setItem('miningSite.rescanMinutes', '5')
    const earlyWithShortDelay = await mountDetail()
    const shortDelayReminder = textOf(earlyWithShortDelay.wrapper, 'site-rescan')

    expect({ lateReminder, earlyReminder, shortDelayReminder }).toEqual({
      lateReminder: expect.stringContaining('Aria Solberg'),
      earlyReminder: null,
      shortDelayReminder: expect.stringContaining('Aria Solberg'),
    })
    expect(lateReminder).toContain(late.t('miningSite.rescan.due'))
    expect(lateReminder).toContain(late.t('miningSite.rescan.hint'))
  })

  it('shows the last ETA of a closed site without colour, countdown, reminder, scan form nor closing button', async () => {
    setNow('21:30:00')
    answer(siteAfterThreeScans({ status: 'closed' }), veldsparAndScorditeScans())

    const { wrapper, t } = await mountDetail()

    expect({
      status: textOf(wrapper, 'site-status'),
      etaTrend: textOf(wrapper, 'site-eta-trend'),
      tier: wrapper.find('[data-testid="site-tier"]').exists(),
      countdown: wrapper.find('[data-testid="site-countdown"]').exists(),
      reminder: wrapper.find('[data-testid="site-rescan"]').exists(),
      form: wrapper.find('[data-testid="scan-form"]').exists(),
      closeButton: wrapper.find('[data-testid="site-close"]').exists(),
      veldsparRowShown: rowOf(wrapper, VELDSPAR).exists(),
    }).toEqual({
      status: t('miningSite.status.closed'),
      etaTrend: expect.stringContaining('20:50'),
      tier: false,
      countdown: false,
      reminder: false,
      form: false,
      closeButton: false,
      veldsparRowShown: true,
    })
  })

  it('shows the error of the server instead of an empty page when the site is not found', async () => {
    vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
      if (endpoint.startsWith(ORE_VALUES_PATH)) return oreValues() as never
      throw new ApiError('Mining site not found', 404)
    })

    const { wrapper } = await mountDetail()

    expect({
      alert: wrapper.find('[role="alert"]').exists() ? wrapper.find('[role="alert"]').text() : null,
      label: wrapper.find('[data-testid="site-label"]').exists(),
      form: wrapper.find('[data-testid="scan-form"]').exists(),
    }).toEqual({ alert: expect.stringContaining('Mining site not found'), label: false, form: false })
  })
})
