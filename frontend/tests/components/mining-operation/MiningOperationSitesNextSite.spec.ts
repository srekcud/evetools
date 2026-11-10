import { enableAutoUnmount, mount } from '@vue/test-utils'
import MiningOperationSites from '@/components/mining-operation/MiningOperationSites.vue'
import MiningSiteCreateModal from '@/components/mining-site/MiningSiteCreateModal.vue'
import type { MiningSite } from '@/stores/miningSite'
import { JASPET, OPERATION_ID, OTHER_SITE_ID, SITE_ID, at, found, i18nFor, siteOf } from '../../views/MiningSite/miningSiteFixtures'

const THIRD_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e52'
const FOURTH_SITE_ID = '0198f1e0-7c1a-7a52-9d3e-5b6a1c2d3e53'

function activeBelt(overrides: Partial<MiningSite> = {}): MiningSite {
  return siteOf({
    id: SITE_ID,
    operationId: OPERATION_ID,
    label: 'Belt Aldranette',
    status: 'active',
    createdAt: at('19:55:00'),
    lastScanAt: at('20:20:00'),
    lastScanNumber: 3,
    lastScanAuthorName: 'Charlie',
    scanCount: 3,
    extractedValue: 600_000,
    ...overrides,
  })
}

function closedAnomaly(overrides: Partial<MiningSite> = {}): MiningSite {
  return siteOf({
    id: OTHER_SITE_ID,
    operationId: OPERATION_ID,
    label: 'Anomalie Spodumain',
    solarSystemId: JASPET,
    solarSystemName: 'Jaspet',
    status: 'closed',
    createdAt: at('18:40:00'),
    closedAt: at('19:50:00'),
    scanCount: 5,
    extractedValue: 1_250_000,
    ...overrides,
  })
}

function mountSites(sites: MiningSite[], canCreate = true) {
  const i18n = i18nFor('en')
  const wrapper = mount(MiningOperationSites, {
    props: { operationId: OPERATION_ID, sites, failure: null, canCreate, now: Date.parse(at('20:30:00')) },
    global: { plugins: [i18n], stubs: { MiningSiteCreateModal: true, RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
  })
  return { wrapper, t: i18n.global.t }
}

function cardOf(wrapper: ReturnType<typeof mountSites>['wrapper'], label: string) {
  const card = wrapper.findAll('[data-testid="operation-site-card"]').find(candidate => candidate.text().includes(label))
  if (card === undefined) throw new Error(`card ${label} not found`)
  return card
}

function cellTexts(wrapper: ReturnType<typeof mountSites>['wrapper'], label: string): Record<string, string | null> {
  const card = cardOf(wrapper, label)
  const textOf = (testId: string) => (card.find(`[data-testid="${testId}"]`).exists() ? card.find(`[data-testid="${testId}"]`).text() : null)
  return {
    start: textOf('operation-site-start'),
    end: textOf('operation-site-end'),
    scans: textOf('operation-site-scans'),
    extracted: textOf('operation-site-extracted'),
  }
}

enableAutoUnmount(afterEach)

describe('MiningOperationSites, next site and site strip', () => {
  it('shows on each card the start, the end or that it is ongoing or unknown, the number of scans and the value extracted', () => {
    const { wrapper, t } = mountSites([
      activeBelt(),
      closedAnomaly(),
      siteOf({ id: THIRD_SITE_ID, operationId: OPERATION_ID, label: 'Belt Plex', status: 'closed', createdAt: at('17:05:00'), scanCount: 2, extractedValue: 90_000 }),
    ])

    const belt = cellTexts(wrapper, 'Belt Aldranette')
    const anomaly = cellTexts(wrapper, 'Anomalie Spodumain')
    const unknownEnd = cellTexts(wrapper, 'Belt Plex')
    expect({
      beltStart: belt.start?.includes('19:55'),
      beltEnd: belt.end?.includes(t('miningOperationSites.endOngoing')),
      beltScans: belt.scans?.includes('3'),
      beltExtracted: [belt.extracted?.includes('600,000'), belt.extracted?.includes('ISK')],
      anomalyStart: anomaly.start?.includes('18:40'),
      anomalyEnd: [anomaly.end?.includes('19:50'), anomaly.end?.includes(t('miningOperationSites.endOngoing'))],
      anomalyScans: anomaly.scans?.includes('5'),
      anomalyExtracted: anomaly.extracted?.includes('1,250,000'),
      unknownEnd: [unknownEnd.end?.includes(t('miningOperationSites.endUnknown')), unknownEnd.end?.includes(t('miningOperationSites.endOngoing'))],
      unknownExtracted: unknownEnd.extracted?.includes('90,000'),
    }).toEqual({
      beltStart: true,
      beltEnd: true,
      beltScans: true,
      beltExtracted: [true, true],
      anomalyStart: true,
      anomalyEnd: [true, false],
      anomalyScans: true,
      anomalyExtracted: true,
      unknownEnd: [true, false],
      unknownExtracted: true,
    })
  })

  it('shows the extracted value as unknown instead of 0, with the hint only below two scans, and keeps a measured 0', () => {
    const { wrapper, t } = mountSites([
      activeBelt({ label: 'Belt un scan', scanCount: 1, extractedValue: null }),
      closedAnomaly({ label: 'Anomalie sans prix', scanCount: 4, extractedValue: null }),
      closedAnomaly({ id: THIRD_SITE_ID, label: 'Belt stable', scanCount: 2, extractedValue: 0 }),
      closedAnomaly({ id: FOURTH_SITE_ID, label: 'Belt vide', scanCount: 0, extractedValue: undefined }),
    ])

    const oneScan = cellTexts(wrapper, 'Belt un scan')
    const noPrice = cellTexts(wrapper, 'Anomalie sans prix')
    const stable = cellTexts(wrapper, 'Belt stable')
    const noScan = cellTexts(wrapper, 'Belt vide')
    expect({
      oneScan: [oneScan.extracted?.includes(t('miningOperationSites.extractedUnknown')), oneScan.extracted?.includes(t('miningOperationSites.extractedHint')), oneScan.scans?.includes('1')],
      noPrice: [noPrice.extracted?.includes(t('miningOperationSites.extractedUnknown')), noPrice.extracted?.includes(t('miningOperationSites.extractedHint'))],
      stable: [stable.extracted?.includes(t('miningOperationSites.extractedUnknown')), stable.extracted?.includes('0 ISK') || stable.extracted?.includes('0 ISK')],
      noScan: [noScan.extracted?.includes(t('miningOperationSites.extractedUnknown')), noScan.extracted?.includes(t('miningOperationSites.extractedHint')), noScan.scans?.includes('0')],
    }).toEqual({
      oneScan: [true, true, true],
      noPrice: [true, false],
      stable: [false, true],
      noScan: [true, true, true],
    })
  })

  it('keeps the label, the system, the status, the last scan and the link to the site on each card', () => {
    const { wrapper, t } = mountSites([activeBelt(), closedAnomaly()])

    const cards = wrapper.findAll('[data-testid="operation-site-card"]')
    expect({
      texts: cards.map(card => card.text()),
      links: cards.map(card => JSON.parse(card.attributes('data-to') ?? 'null')),
    }).toEqual({
      texts: [
        expect.stringMatching(/Belt Aldranette.*Aldranette.*Active.*Last scan #3 by Charlie, 10 min ago/),
        expect.stringMatching(new RegExp(`Anomalie Spodumain.*Jaspet.*Closed.*${t('miningOperationSites.noScan')}`, 'i')),
      ],
      links: [
        { name: 'mining-site-detail', params: { id: SITE_ID } },
        { name: 'mining-site-detail', params: { id: OTHER_SITE_ID } },
      ],
    })
  })

  it('offers the next site only to a participant who can create and while a site is active, next to the new site button', () => {
    const withActive = mountSites([activeBelt(), closedAnomaly()])
    const withActiveState = [withActive.wrapper.find('[data-testid="operation-site-next"]').exists(), withActive.wrapper.find('[data-testid="operation-site-create"]').exists(), withActive.wrapper.find('[data-testid="operation-site-next"]').text()]
    const withActiveLabel = withActive.t('miningOperationSites.next')
    withActive.wrapper.unmount()
    const onlyClosed = mountSites([closedAnomaly()])
    const onlyClosedState = [onlyClosed.wrapper.find('[data-testid="operation-site-next"]').exists(), onlyClosed.wrapper.find('[data-testid="operation-site-create"]').exists()]
    onlyClosed.wrapper.unmount()
    const empty = mountSites([])
    const emptyState = [empty.wrapper.find('[data-testid="operation-site-next"]').exists(), empty.wrapper.find('[data-testid="operation-site-create"]').exists()]
    empty.wrapper.unmount()
    const readOnly = mountSites([activeBelt()], false)

    expect({
      withActiveState,
      onlyClosedState,
      emptyState,
      readOnlyState: [readOnly.wrapper.find('[data-testid="operation-site-next"]').exists(), readOnly.wrapper.find('[data-testid="operation-site-create"]').exists()],
    }).toEqual({
      withActiveState: [true, true, withActiveLabel],
      onlyClosedState: [false, true],
      emptyState: [false, true],
      readOnlyState: [false, false],
    })
  })

  it('opens the creation window closing the active sites from the next site button and the plain one from the new site button', async () => {
    const { wrapper } = mountSites([activeBelt()])
    const modal = () => wrapper.findComponent(MiningSiteCreateModal)

    const initial = [modal().props('show'), modal().props('closesActive')]
    await found(wrapper, '[data-testid="operation-site-next"]').trigger('click')
    const afterNext = [modal().props('show'), modal().props('closesActive'), modal().props('operationId')]
    await modal().vm.$emit('close')
    await found(wrapper, '[data-testid="operation-site-create"]').trigger('click')
    const afterCreate = [modal().props('show'), modal().props('closesActive') === true]

    expect({ initial: [initial[0], initial[1] === true], afterNext, afterCreate }).toEqual({
      initial: [false, false],
      afterNext: [true, true, OPERATION_ID],
      afterCreate: [true, false],
    })
  })

  it('translates every text of the site strip and of the next site window in French and in English', () => {
    const keys = [
      ...['next', 'start', 'end', 'endOngoing', 'endUnknown', 'scans', 'extracted', 'extractedUnknown', 'extractedHint'].map(name => `miningOperationSites.${name}`),
      'miningSite.create.closesActive',
    ]

    expect(Object.fromEntries((['en', 'fr'] as const).map(locale => [locale, keys.filter(key => !i18nFor(locale).global.te(key, locale))]))).toEqual({ en: [], fr: [] })
  })
})
