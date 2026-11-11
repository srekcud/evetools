import { enableAutoUnmount, flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { useFormatters } from '@/composables/useFormatters'
import { useAuthStore } from '@/stores/auth'
import Dashboard from '@/views/Dashboard.vue'
import { FailedAnswer, stubBackend, type BackendAnswer } from '../backendStub'
import { INVALID_TOKEN, TRIGGERED_MEXALLON_ALERT, TRIGGERED_PYERITE_ALERT, planetaryStats, todoUser } from '../composables/todoSources'

const NOW = '2026-11-10T12:00:00Z'
const OPERATIONS_URL = '/api/mining-operations'
const SLOTS_URL = '/api/industry/slots'
const EXTRACTIONS_URL = '/api/mining/corporation-extractions'
const PLANETARY_STATS_URL = '/api/planetary/stats'
const LEDGER_URL = '/api/ledger/dashboard?days=30'
const UNKNOWN_VALUE_SELECTOR = `span[title="${fr.common.unknownValue}"]`
const PROGRESS_CARDS = ['mining-operations', 'jobs', 'chunks', 'extractors'] as const

type ProgressCard = typeof PROGRESS_CARDS[number]

function iskFormatterIn(locale: 'en' | 'fr'): (amount: number) => string {
  let formatIsk!: (amount: number) => string
  const app = createApp({
    setup: () => {
      formatIsk = useFormatters().formatIsk
      return () => null
    },
  })
  app.use(createI18n({ legacy: false, locale, messages: { en, fr } }))
  app.mount(document.createElement('div'))
  app.unmount()
  return formatIsk
}

const formatIsk = iskFormatterIn('fr')

function miningOperation(id: string, label: string, status: string) {
  return { id, label, status, plannedStartAt: '2026-11-10T08:00:00+00:00', participantsCount: 3, charactersCount: 5 }
}

const OPERATIONS = [
  miningOperation('0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a41', 'Veldspar Run Alpha', 'in_progress'),
  miningOperation('0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a42', 'Scordite Sweep', 'planned'),
  miningOperation('0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a43', 'Moon Burn Zeta', 'in_progress'),
  miningOperation('0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a44', 'Long Ice Haul Operation With A Very Long Name Indeed', 'in_progress'),
  miningOperation('0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a45', 'Pyroxeres Night Shift', 'in_progress'),
]

function timelineJob(jobId: number, productTypeName: string | null, characterName: string, endDate: string, timeLeftSeconds: number, project: { id: string; name: string | null } | null = null) {
  return { jobId, characterName, productTypeId: 20_000 + jobId, productTypeName, activityType: 'manufacturing', runs: 4, timeLeftSeconds, endDate, project }
}

const SLOTS = {
  globalKpis: {},
  characters: [],
  timeline: [
    timelineJob(11, 'Raven Navy Issue', 'Ilyra Castell', '2026-11-11T10:00:00+00:00', 79_200),
    timelineJob(12, null, 'Doran Vex', '2026-11-10T13:30:00+00:00', 5_400, { id: '7c1d2e3f-0a1b-4c2d-8e3f-9a0b1c2d3e4f', name: null }),
    timelineJob(13, 'Hobgoblin II', 'Ilyra Castell', '2026-11-10T18:00:00+00:00', 21_600),
    timelineJob(14, 'Damage Control II', 'Doran Vex', '2026-11-12T08:00:00+00:00', 164_800),
    timelineJob(15, 'Warp Disruptor II', 'Ilyra Castell', '2026-11-10T12:45:00+00:00', 2_700, { id: '3a9b8c7d-6e5f-4a4b-9c3d-2e1f0a9b8c7d', name: 'Hammerhead Wing Refit' }),
  ],
  skillsMayBeStale: false,
  unknownSkillsCharacterCount: 0,
}

function extraction(id: string, moonName: string, chunkArrivalTime: string, totalValue: number | null) {
  return {
    id,
    structureId: 1_040_000_000_100,
    structureName: 'Athanor Prime',
    solarSystemId: 30_000_142,
    solarSystemName: 'Jita',
    moonId: 40_000_000 + Number(id),
    moonName,
    extractionStartTime: '2026-11-08T00:00:00+00:00',
    chunkArrivalTime,
    naturalDecayTime: '2026-11-30T00:00:00+00:00',
    status: 'extracting',
    chunkValue: totalValue === null ? null : { status: 'known', reason: null, volumeM3: 12_000_000, totalValue, perParticipant: null, lines: null, scanRecordedAt: null },
    linkedOperation: null,
    linkedOperationCount: 0,
  }
}

const EXTRACTIONS = {
  corporations: [{
    corporationId: 98_000_230,
    access: 'ok',
    lastSuccessAt: '2026-11-10T11:00:00+00:00',
    followedStructureIds: [],
    extractions: [
      extraction('1', 'Past Moon - Moon 2', '2026-11-10T08:00:00+00:00', 900_000_000),
      extraction('2', 'Amarr Prime - Moon 1', '2026-11-12T09:00:00+00:00', null),
      extraction('3', 'Dodixie IX - Moon 20', '2026-11-11T18:30:00+00:00', 1_750_000_000),
      extraction('4', 'Jita IV - Moon 4', '2026-11-14T05:00:00+00:00', 320_000_000),
      extraction('5', 'Rens VI - Moon 8', '2026-11-20T00:00:00+00:00', 640_000_000),
    ],
  }],
}

const LEDGER_TOTALS = { total: 4_810_000, pve: 1_250_000, mining: 480_000, industry: 3_080_000, expenses: 120_000, profit: 4_690_000 }

function ledgerDashboard(totals: Record<string, number | null>) {
  return { period: { from: '2026-10-11', to: '2026-11-10', days: 30 }, totals }
}

const EXTRACTORS = { ...planetaryStats({ expiredExtractors: 2 }), expiringExtractors: 3, expiryThresholdMinutes: 360 }

const WALLETS = { id: 'current', totalBalance: 2_480_000.25, incomplete: false, wallets: [] }

function healthyBackend(): Record<string, BackendAnswer> {
  return {
    'GET /api/me/wallets': WALLETS,
    'GET /api/market/alerts': [],
    'GET /api/me/bridge/acknowledgements': [],
    [`GET ${PLANETARY_STATS_URL}`]: EXTRACTORS,
    [`GET ${OPERATIONS_URL}`]: OPERATIONS,
    [`GET ${SLOTS_URL}`]: SLOTS,
    [`GET ${EXTRACTIONS_URL}`]: EXTRACTIONS,
    [`GET ${LEDGER_URL}`]: ledgerDashboard(LEDGER_TOTALS),
  }
}

const SOURCE_URL_OF_CARD: Record<ProgressCard, string> = {
  'mining-operations': OPERATIONS_URL,
  jobs: SLOTS_URL,
  chunks: EXTRACTIONS_URL,
  extractors: PLANETARY_STATS_URL,
}

async function mountBridge(answers: Record<string, BackendAnswer>, locale: 'en' | 'fr' = 'fr'): Promise<VueWrapper> {
  stubBackend(answers)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = todoUser()
  const wrapper = mount(Dashboard, {
    global: {
      plugins: [pinia, createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        LoadingSpinner: true,
        RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

function card(wrapper: VueWrapper, id: ProgressCard): DOMWrapper<Element> {
  const found = wrapper.find(`[data-testid="dashboard-progress-${id}"]`)
  expect(found.exists(), `progress card ${id}`).toBe(true)
  return found
}

function cardItems(wrapper: VueWrapper, id: ProgressCard): DOMWrapper<Element>[] {
  return card(wrapper, id).findAll('[data-testid="dashboard-progress-item"]')
}

function cardStates(wrapper: VueWrapper): Record<string, string | undefined> {
  return Object.fromEntries(PROGRESS_CARDS.map(id => [id, card(wrapper, id).attributes('data-state')]))
}

function cardText(wrapper: VueWrapper, id: ProgressCard): string {
  return card(wrapper, id).text().replace(/\s+/g, ' ')
}

function cardLink(wrapper: VueWrapper, id: ProgressCard): string | undefined {
  return card(wrapper, id).find('a[data-testid="dashboard-progress-link"]').attributes('href')
}

function balanceRows(wrapper: VueWrapper): DOMWrapper<Element>[] {
  const block = wrapper.find('[data-testid="dashboard-balance"]')
  expect(block.exists(), 'balance block').toBe(true)
  return block.findAll('[data-testid="dashboard-balance-row"]')
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date(NOW) })
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('Dashboard Bridge to-do block', () => {
  it('writes the severity of each row in words next to its colour, and nothing for an unknown source', async () => {
    const wrapper = await mountBridge({
      ...healthyBackend(),
      [`GET ${PLANETARY_STATS_URL}`]: planetaryStats({ expiredExtractors: 1, expiringExtractors: 3 }),
      'GET /api/market/alerts': [TRIGGERED_PYERITE_ALERT, TRIGGERED_MEXALLON_ALERT],
    })
    useAuthStore().user = todoUser({ doran: INVALID_TOKEN })
    await flushPromises()
    const levels = (target: VueWrapper) => target.findAll('[data-testid="dashboard-todo"] li').map((row) => {
      const level = row.find('[data-testid="dashboard-todo-level"]')
      return level.exists() ? level.text() : ''
    })

    const levelsOfKnownSources = levels(wrapper)
    wrapper.unmount()
    const withFailedAlerts = await mountBridge({
      ...healthyBackend(),
      [`GET ${PLANETARY_STATS_URL}`]: planetaryStats(),
      'GET /api/market/alerts': new FailedAnswer(),
    })

    expect(levelsOfKnownSources).toEqual(['Critique', 'Critique', 'Attention', 'Info'])
    expect(levels(withFailedAlerts)).toEqual([''])
  })
})

describe('Dashboard Bridge in-progress cards', () => {
  it('counts the mining operations in progress and lists three of them with a tooltip and a link to their detail', async () => {
    const wrapper = await mountBridge(healthyBackend())

    const items = cardItems(wrapper, 'mining-operations')

    expect(card(wrapper, 'mining-operations').attributes('data-state')).toBe('loaded')
    expect(cardText(wrapper, 'mining-operations')).toContain('4')
    expect(items.map(item => item.attributes('title') ?? item.find('[title]').attributes('title'))).toEqual(['Veldspar Run Alpha', 'Moon Burn Zeta', 'Long Ice Haul Operation With A Very Long Name Indeed'])
    expect(items.map(item => item.find('a').attributes('href'))).toEqual([
      '/mining/operations/0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a41',
      '/mining/operations/0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a43',
      '/mining/operations/0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a44',
    ])
    expect(cardLink(wrapper, 'mining-operations')).toBe('/mining/operations')
  })

  it('lists the four jobs that finish first, soonest first, with product, character and an unknown product named as such', async () => {
    const wrapper = await mountBridge(healthyBackend())

    const items = cardItems(wrapper, 'jobs')

    expect(items).toHaveLength(4)
    expect(items.map(item => item.text())).toEqual([
      expect.stringContaining('Warp Disruptor II'),
      expect.stringContaining('produit inconnu'),
      expect.stringContaining('Hobgoblin II'),
      expect.stringContaining('Raven Navy Issue'),
    ])
    expect(items.map((item, index) => item.text().includes(['Ilyra Castell', 'Doran Vex', 'Ilyra Castell', 'Ilyra Castell'][index]))).toEqual([true, true, true, true])
    expect(cardText(wrapper, 'jobs')).not.toContain('Damage Control II')
    expect(cardLink(wrapper, 'jobs')).toBe('/industry/production?tab=slots')
  })

  it('links a job to its project by the project name, or by the project fallback label when it has no name, and shows no link for a job without a project', async () => {
    const wrapper = await mountBridge(healthyBackend())

    const items = cardItems(wrapper, 'jobs')
    const projectLinks = items.map(item => item.findAll('a').map(link => ({ text: link.text(), href: link.attributes('href') })))

    expect(projectLinks).toEqual([
      [{ text: 'Hammerhead Wing Refit', href: '/industry/projects/3a9b8c7d-6e5f-4a4b-9c3d-2e1f0a9b8c7d' }],
      [{ text: 'Projet', href: '/industry/projects/7c1d2e3f-0a1b-4c2d-8e3f-9a0b1c2d3e4f' }],
      [],
      [],
    ])
  })

  it('lists the three next moon chunks still to come by arrival, with their value or an unknown value', async () => {
    const wrapper = await mountBridge(healthyBackend())

    const items = cardItems(wrapper, 'chunks')

    expect(items).toHaveLength(3)
    expect(items.map(item => item.text())).toEqual([
      expect.stringContaining('Dodixie IX - Moon 20'),
      expect.stringContaining('Amarr Prime - Moon 1'),
      expect.stringContaining('Jita IV - Moon 4'),
    ])
    expect(items[0].text()).toContain(formatIsk(1_750_000_000))
    expect(items[1].find(UNKNOWN_VALUE_SELECTOR).exists()).toBe(true)
    expect(items[2].text()).toContain(formatIsk(320_000_000))
    expect(cardText(wrapper, 'chunks')).not.toContain('Past Moon')
    expect(cardText(wrapper, 'chunks')).not.toContain('Rens VI')
    expect(cardLink(wrapper, 'chunks')).toBe('/mining/moons')
  })

  it('shows the active extractors over the total, the stopped ones and the ones expiring at the threshold', async () => {
    const wrapper = await mountBridge(healthyBackend())

    const text = cardText(wrapper, 'extractors')

    expect(card(wrapper, 'extractors').attributes('data-state')).toBe('loaded')
    expect(['6', '8', '2', '3'].filter(figure => !text.includes(figure))).toEqual([])
    expect(cardLink(wrapper, 'extractors')).toBe('/planetary')
  })

  it('gives each card an empty state of its own when its source has nothing to show', async () => {
    const wrapper = await mountBridge({
      ...healthyBackend(),
      [`GET ${OPERATIONS_URL}`]: [OPERATIONS[1]],
      [`GET ${SLOTS_URL}`]: { ...SLOTS, timeline: [] },
      [`GET ${EXTRACTIONS_URL}`]: { corporations: [{ ...EXTRACTIONS.corporations[0], extractions: [EXTRACTIONS.corporations[0].extractions[0]] }] },
      [`GET ${PLANETARY_STATS_URL}`]: { ...planetaryStats(), totalExtractors: 0, activeExtractors: 0 },
    })

    expect(cardStates(wrapper)).toEqual({ 'mining-operations': 'empty', jobs: 'empty', chunks: 'empty', extractors: 'empty' })
    expect(PROGRESS_CARDS.map(id => cardItems(wrapper, id).length)).toEqual([0, 0, 0, 0])
  })

  it('shows an unknown card, never a zero, for the one source that fails while the three other cards stay loaded', async () => {
    const outcomes: Record<string, { states: Record<string, string | undefined>; text: string }> = {}
    for (const failing of PROGRESS_CARDS) {
      const wrapper = await mountBridge({ ...healthyBackend(), [`GET ${SOURCE_URL_OF_CARD[failing]}`]: new FailedAnswer() })
      outcomes[failing] = { states: cardStates(wrapper), text: cardText(wrapper, failing) }
      wrapper.unmount()
    }

    expect(Object.fromEntries(Object.entries(outcomes).map(([failing, outcome]) => [failing, outcome.states]))).toEqual({
      'mining-operations': { 'mining-operations': 'unknown', jobs: 'loaded', chunks: 'loaded', extractors: 'loaded' },
      jobs: { 'mining-operations': 'loaded', jobs: 'unknown', chunks: 'loaded', extractors: 'loaded' },
      chunks: { 'mining-operations': 'loaded', jobs: 'loaded', chunks: 'unknown', extractors: 'loaded' },
      extractors: { 'mining-operations': 'loaded', jobs: 'loaded', chunks: 'loaded', extractors: 'unknown' },
    })
    expect(Object.values(outcomes).filter(outcome => /\b0\b/.test(outcome.text))).toEqual([])
  })

  it('loads each card on its own, a card still waiting for its source not holding back the others', async () => {
    const answers = healthyBackend()
    delete answers[`GET ${EXTRACTIONS_URL}`]
    const wrapper = await mountBridge(answers)

    expect(cardStates(wrapper)).toEqual({ 'mining-operations': 'loaded', jobs: 'loaded', chunks: 'loading', extractors: 'loaded' })
  })
})

describe('Dashboard Bridge balance of the last 30 days', () => {
  it('shows PVE, Mining, Industry then Total of the last 30 days under their title, with a link to the balance', async () => {
    const wrapper = await mountBridge(healthyBackend())

    const rows = balanceRows(wrapper)

    expect(wrapper.get('[data-testid="dashboard-balance"]').text()).toContain('Bilan des 30 derniers jours')
    expect(rows.map(row => row.text())).toEqual([
      expect.stringContaining(formatIsk(1_250_000)),
      expect.stringContaining(formatIsk(480_000)),
      expect.stringContaining(formatIsk(3_080_000)),
      expect.stringContaining(formatIsk(4_810_000)),
    ])
    expect(rows.map(row => row.text().split(/\s/)[0])).toEqual(['PVE', 'Minage', 'Industrie', 'Total'])
    expect(wrapper.find('[data-testid="dashboard-balance"] a[href="/balance"]').exists()).toBe(true)
  })

  it('shows an unknown value, never a zero, for an amount the server cannot compute, and an unknown block when the ledger fails', async () => {
    const withUnknownAmounts = await mountBridge({ ...healthyBackend(), [`GET ${LEDGER_URL}`]: ledgerDashboard({ ...LEDGER_TOTALS, mining: null, total: null }) })
    const rows = balanceRows(withUnknownAmounts)
    const unknownMarks = rows.map(row => row.find(UNKNOWN_VALUE_SELECTOR).exists())
    const textOfUnknownAmounts = withUnknownAmounts.get('[data-testid="dashboard-balance"]').text()
    withUnknownAmounts.unmount()

    const failed = await mountBridge({ ...healthyBackend(), [`GET ${LEDGER_URL}`]: new FailedAnswer() })

    expect(unknownMarks).toEqual([false, true, false, true])
    expect(textOfUnknownAmounts).not.toMatch(/\b0\b/)
    expect(failed.get('[data-testid="dashboard-balance"]').attributes('data-state')).toBe('unknown')
    expect(failed.get('[data-testid="dashboard-balance"]').text()).not.toMatch(/\b0\b/)
    expect(cardStates(failed)).toEqual({ 'mining-operations': 'loaded', jobs: 'loaded', chunks: 'loaded', extractors: 'loaded' })
  })
})

describe('Dashboard Bridge place in the page', () => {
  it('shows the three Bridge blocks before the existing KPI, character cards and activity feed, which are all still rendered', async () => {
    const wrapper = await mountBridge(healthyBackend(), 'en')

    const todo = wrapper.get('[data-testid="dashboard-todo"]').element
    const progress = card(wrapper, 'jobs').element
    const balance = wrapper.get('[data-testid="dashboard-balance"]').element
    const charactersKpi = wrapper.findAll('span').find(span => span.text() === 'Characters')?.element
    const yourCharacters = wrapper.findAll('h3').find(heading => heading.text() === 'Your characters')?.element
    const activity = wrapper.findAll('h3').find(heading => heading.text() === 'Activity')?.element

    expect([charactersKpi, yourCharacters, activity].map(element => element !== undefined)).toEqual([true, true, true])
    expect(todo.compareDocumentPosition(progress) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(progress.compareDocumentPosition(balance) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect([charactersKpi, yourCharacters, activity].map(later => balance.compareDocumentPosition(later as Element) & Node.DOCUMENT_POSITION_FOLLOWING)).toEqual([4, 4, 4])
  })
})
