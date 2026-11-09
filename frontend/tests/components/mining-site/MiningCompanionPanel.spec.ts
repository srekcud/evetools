import { enableAutoUnmount, mount } from '@vue/test-utils'
import MiningCompanionPanel from '@/components/mining-site/MiningCompanionPanel.vue'
import type { MiningSite, MiningSiteScan } from '@/stores/miningSite'
import {
  OPERATION_ID,
  SCORDITE,
  VELDSPAR,
  at,
  i18nFor,
  presentType,
  scanOf,
  siteAfterThreeScans,
  siteOf,
  veldsparAndScorditeScans,
} from '../../views/MiningSite/miningSiteFixtures'

const UNKNOWN_DASH = '–'
const TYPE_NAMES = { [VELDSPAR]: 'Veldspar', [SCORDITE]: 'Scordite' }
const RESCAN_MINUTES = 10

interface PanelProps {
  site: MiningSite | null
  scans: MiningSiteScan[]
  size: 'bar' | 'compact' | 'detail'
  now: Date
  rescanMinutes: number
  typeNames: Record<number, string>
  operation: { id: string; status: string; myDepartedAt: string | null } | null
  notice: string | null
}

function mountPanel(overrides: Partial<PanelProps> = {}) {
  const i18n = i18nFor('en')
  const props: PanelProps = {
    site: siteAfterThreeScans(),
    scans: veldsparAndScorditeScans(),
    size: 'compact',
    now: new Date(at('20:25:00')),
    rescanMinutes: RESCAN_MINUTES,
    typeNames: TYPE_NAMES,
    operation: null,
    notice: null,
    ...overrides,
  }
  const wrapper = mount(MiningCompanionPanel, { props, global: { plugins: [i18n] } })
  return { wrapper, t: i18n.global.t }
}

function textOf(wrapper: ReturnType<typeof mountPanel>['wrapper'], testId: string): string | null {
  const element = wrapper.find(`[data-testid="${testId}"]`)
  return element.exists() ? element.text() : null
}

function inProgress(myDepartedAt: string | null = null) {
  return { id: OPERATION_ID, status: 'in_progress', myDepartedAt }
}

function slowTrendScans(): { site: MiningSite; scans: MiningSiteScan[] } {
  const trendEta = at('20:52:00')
  const scans = [
    scanOf(1, at('20:09:00'), [presentType(VELDSPAR, 30_000, { volumeM3: 3_000 })], { authorName: 'Aria Solberg' }),
    scanOf(2, at('20:29:00'), [presentType(VELDSPAR, 18_000, { volumeM3: 1_800, trendEta })], { authorName: 'Brann Holt', trendEta }),
  ]
  return { site: siteAfterThreeScans({ lastScanAt: at('20:29:00'), lastScanNumber: 2, lastScanAuthorName: 'Brann Holt', trendEta }), scans }
}

enableAutoUnmount(afterEach)

describe('MiningCompanionPanel', () => {
  it('shows only the site name, the trend ETA and its countdown in the bar size', () => {
    const { wrapper } = mountPanel({ size: 'bar' })

    expect({
      text: wrapper.text(),
      eta: textOf(wrapper, 'companion-eta'),
      countdown: textOf(wrapper, 'companion-countdown'),
      ring: wrapper.find('[data-testid="companion-ring"]').exists(),
      veldsparRow: wrapper.find(`[data-testid="companion-type-row-${VELDSPAR}"]`).exists(),
      scorditeRow: wrapper.find(`[data-testid="companion-type-row-${SCORDITE}"]`).exists(),
    }).toEqual({
      text: expect.stringContaining('Belt Aldranette'),
      eta: expect.stringContaining('20:50'),
      countdown: expect.stringContaining('25:33'),
      ring: false,
      veldsparRow: false,
      scorditeRow: false,
    })
  })

  it('adds the ring, the colour label, the last ore, the last scan and one row per type in the detail size, and emits paste', async () => {
    const { wrapper, t } = mountPanel({ size: 'detail' })

    await wrapper.find('[data-testid="companion-paste"]').trigger('click')

    expect({
      ring: textOf(wrapper, 'companion-ring'),
      tier: textOf(wrapper, 'companion-tier'),
      lastOre: textOf(wrapper, 'companion-last-ore'),
      lastScan: textOf(wrapper, 'companion-last-scan'),
      rescan: wrapper.find('[data-testid="companion-rescan"]').exists(),
      veldsparRow: textOf(wrapper, `companion-type-row-${VELDSPAR}`),
      scorditeRow: textOf(wrapper, `companion-type-row-${SCORDITE}`),
      pastes: wrapper.emitted('paste')?.length,
    }).toEqual({
      ring: expect.stringContaining('60'),
      tier: expect.stringContaining(t('miningSite.tier.normal')),
      lastOre: expect.stringContaining('Veldspar'),
      lastScan: t('miningSite.lastScan.text', { number: 3, author: 'Aria Solberg', age: t('miningSite.lastScan.ago', { minutes: 5 }) }),
      rescan: false,
      veldsparRow: expect.stringMatching(/Veldspar[\s\S]*20:45[\s\S]*20:50/),
      scorditeRow: expect.stringMatching(new RegExp(`Scordite[\\s\\S]*${UNKNOWN_DASH}`)),
      pastes: 1,
    })
  })

  it('turns orange with a 12:00 countdown when the trend ETA is in 12 minutes, and asks for a re-scan only when the last scan is older than the delay', () => {
    const { site, scans } = slowTrendScans()
    const now = new Date(at('20:40:00'))

    const delayTen = mountPanel({ site, scans, now, rescanMinutes: 10 })
    const delayTwelve = mountPanel({ site, scans, now, rescanMinutes: 12 })

    expect({
      tier: textOf(delayTen.wrapper, 'companion-tier'),
      countdown: textOf(delayTen.wrapper, 'companion-countdown'),
      rescanAfterTen: textOf(delayTen.wrapper, 'companion-rescan'),
      rescanAfterTwelve: delayTwelve.wrapper.find('[data-testid="companion-rescan"]').exists(),
    }).toEqual({
      tier: expect.stringContaining(delayTen.t('miningSite.tier.orange')),
      countdown: expect.stringContaining('12:00'),
      rescanAfterTen: expect.stringContaining(delayTen.t('miningSite.rescan.due')),
      rescanAfterTwelve: false,
    })
  })

  it('says the ETA is unknown with the unknown tier and a dash for the ring, never zero, when the last scan has no trend ETA', () => {
    const singleScan = scanOf(1, at('20:20:00'), [presentType(VELDSPAR, 60_000, { etaUnknownReason: 'single_scan' })], { authorName: 'Aria Solberg' })
    const site = siteOf({ firstScanAt: at('20:20:00'), lastScanAt: at('20:20:00'), lastScanNumber: 1, lastScanAuthorName: 'Aria Solberg' })

    const { wrapper, t } = mountPanel({ site, scans: [singleScan], size: 'compact' })

    expect({
      eta: textOf(wrapper, 'companion-eta'),
      tier: textOf(wrapper, 'companion-tier'),
      ring: textOf(wrapper, 'companion-ring'),
      zeroCountdown: wrapper.text().includes('00:00'),
    }).toEqual({
      eta: t('miningSite.eta.unknown'),
      tier: expect.stringContaining(t('miningSite.tier.unknown')),
      ring: expect.stringContaining(UNKNOWN_DASH),
      zeroCountdown: false,
    })
  })

  it('shows the empty state without any estimate when there is no active site, shows the notice and emits open-desk', async () => {
    const { wrapper } = mountPanel({ site: null, scans: [], notice: 'Popup blocked: allow popups' })

    await wrapper.find('[data-testid="companion-desk"]').trigger('click')

    expect({
      empty: wrapper.find('[data-testid="companion-empty"]').exists(),
      eta: wrapper.find('[data-testid="companion-eta"]').exists(),
      ring: wrapper.find('[data-testid="companion-ring"]').exists(),
      notice: wrapper.text(),
      openDesk: wrapper.emitted('open-desk')?.length,
    }).toEqual({
      empty: true,
      eta: false,
      ring: false,
      notice: expect.stringContaining('Popup blocked: allow popups'),
      openDesk: 1,
    })
  })

  it('asks for a confirmation on the first click of leave and emits leave on the second, only for an operation in progress the user has not left', async () => {
    const { wrapper } = mountPanel({ operation: inProgress() })
    const leaveButton = () => wrapper.find('[data-testid="companion-leave"]')
    const labelBefore = leaveButton().text()

    await leaveButton().trigger('click')
    const afterFirstClick = { leaves: wrapper.emitted('leave')?.length, labelChanged: leaveButton().text() !== labelBefore }
    await leaveButton().trigger('click')

    const unavailable = [
      mountPanel({ operation: null }),
      mountPanel({ operation: { ...inProgress(), status: 'closing' } }),
      mountPanel({ operation: inProgress(at('20:10:00')) }),
    ].map(({ wrapper: other }) => other.find('[data-testid="companion-leave"]').exists())

    expect({ afterFirstClick, leaves: wrapper.emitted('leave')?.length, unavailable }).toEqual({
      afterFirstClick: { leaves: undefined, labelChanged: true },
      leaves: 1,
      unavailable: [false, false, false],
    })
  })
})
