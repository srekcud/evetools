import { enableAutoUnmount, flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectFlowCardDetail from '@/components/group-industry/ProjectFlowCardDetail.vue'
import type { FlowCard } from '@/components/group-industry/projectFlow'
import { apiRequest } from '@/services/api'
import type { BomItem, GroupProject, JobMatch, SellForecast } from '@/stores/group-industry/types'
import { readyCourier } from '../../courierFixtures'
import { NOCXIUM_UNKNOWN, projectMaterialSourcing, STRUCTURE_NAME, TRITANIUM_AT_JITA } from '../../materialSourcingRows'
import type { TestLocale } from '../../localeTexts'
import { sourcingDecisions } from '../../projectSetupFixtures'
import { bomLineOf, flowCard, ISHTAR, projectOf, TRITANIUM } from './projectFlowFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const PROJECT_ID = 'project-ishtar-batch'
const NOCXIUM = { typeId: 38, typeName: 'Nocxium' }
const HAMMERHEAD = { typeId: 11_993, typeName: 'Hammerhead II' }
const JITA_MARKET_NAME = 'Jita IV - Moon 4'
const ORIGIN = '[data-testid="flow-detail-origin"]'
const DETAIL_KEYS = 'groupIndustry.flow.detail'
const STANDALONE_ZERO = /(^|[^\d.,])0([^\d.,]|$)/

const SUPPLY_ORIGINS = ['whereToBuy', 'courier', 'contributions']
const JOB_ORIGINS = ['jobs', 'reservation', 'planning', 'structure']
const SALES_ORIGINS = ['whereToSell', 'sellOrders', 'salesToMatch']

const MESSAGES: Record<TestLocale, unknown> = { en, fr }

function localeText(locale: TestLocale, key: string): string {
  const message = key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], MESSAGES[locale])
  return typeof message === 'string' ? message : `missing ${locale} message ${key}`
}

function squashed(text: string): string {
  return text.replace(/[\u00a0\u202f]/g, ' ').replace(/\s+/g, ' ').trim()
}

function sellForecast(): SellForecast {
  const market = { unitPrice: 760_000, coveragePercent: 80, feeRate: 0.06, netUnitPrice: 714_400, netRevenue: 8_572_800, daysToSell: 3 }
  return {
    projectId: PROJECT_ID,
    sellStructure: { id: 1_035_466_543_993, name: STRUCTURE_NAME },
    products: [{ ...HAMMERHEAD, quantity: 12, structure: { name: STRUCTURE_NAME, ...market }, jita: { name: JITA_MARKET_NAME, ...market, netUnitPrice: 701_000 } }],
    totals: { structureNetRevenue: null, jitaNetRevenue: null, structureUnknownProductCount: 0, jitaUnknownProductCount: 0, remainingCost: null, remainingCostUnknownLineCount: 0, structureProfit: null, jitaProfit: null },
  }
}

function defaultAnswers(): Record<string, unknown> {
  return {
    'material-sourcing': projectMaterialSourcing(PROJECT_ID, [TRITANIUM_AT_JITA, NOCXIUM_UNKNOWN]),
    courier: readyCourier(PROJECT_ID),
    contributions: [],
    'sell-forecast': sellForecast(),
    'sell-orders': { projectId: PROJECT_ID, orders: [], charactersToReconnect: [], syncedAt: null },
    planning: { id: PROJECT_ID, anchor: '2026-11-20T08:00:00+00:00', structureTimeBonusIncluded: false, plannedEnd: null, expectedEnd: null, delaySeconds: null, delayCause: null, lanesShown: true, characters: [], unscheduled: [] },
    'structure-health': { id: PROJECT_ID, rows: [] },
    research: { id: PROJECT_ID, rows: [] },
    'line-blueprints': [],
    'line-blueprint-candidates': [],
    'sale-suggestions': { id: PROJECT_ID, suggestions: [], walletReadings: [] },
    'contract-suggestions': { projectId: PROJECT_ID, candidates: [], unreadableCharacters: [] },
    sales: [],
    invention: { id: PROJECT_ID, products: [] },
    'sourcing-decisions': { ...sourcingDecisions(null, []), id: PROJECT_ID },
  }
}

function answerApi(overrides: Record<string, unknown> = {}): void {
  const answers = { ...defaultAnswers(), ...overrides }
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const resource = endpoint.split('?')[0].replace(`/industry/projects/${PROJECT_ID}/`, '')
    const answer = answers[resource]
    if (answer instanceof Error) throw answer
    return answer
  })
}

function calledResources(): string[] {
  return vi.mocked(apiRequest).mock.calls.map(([endpoint]) => String(endpoint).split('?')[0].replace(`/industry/projects/${PROJECT_ID}/`, ''))
}

interface DetailProps {
  card: FlowCard
  line: BomItem | undefined
  project?: GroupProject
  canLinkJobs?: boolean
  canRecordSales?: boolean
}

async function mountDetail(props: DetailProps, locale: TestLocale = 'en'): Promise<VueWrapper> {
  setActivePinia(createPinia())
  const wrapper = mount(ProjectFlowCardDetail, {
    props: { projectId: PROJECT_ID, project: projectOf(), canLinkJobs: false, canRecordSales: false, ...props },
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub, JobLinkModal: true, teleport: true },
    },
  })
  await flushPromises()
  return wrapper
}

function originTexts(wrapper: VueWrapper): string[] {
  return wrapper.findAll(ORIGIN).map(origin => squashed(origin.text()))
}

function originLabels(locale: TestLocale, kinds: string[]): string[] {
  return kinds.map(kind => localeText(locale, `${DETAIL_KEYS}.origins.${kind}`))
}

function tritaniumToBuy(): { card: FlowCard; line: BomItem } {
  return {
    card: flowCard('toBuy', { ...TRITANIUM, quantity: 12_000, key: 'toBuy:34:jita' }),
    line: bomLineOf({ id: 'line-tritanium', ...TRITANIUM, requiredQuantity: 12_000, fulfilledQuantity: 3_000, remainingQuantity: 9_000 }),
  }
}

function clickCloseButton(wrapper: VueWrapper, locale: TestLocale): Promise<void> | undefined {
  const closeLabel = localeText(locale, `${DETAIL_KEYS}.closeDetail`)
  return wrapper.findAll('button').find(button => button.text() === '✕' || button.attributes('aria-label') === closeLabel)?.trigger('click')
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset()
  answerApi()
})

describe('ProjectFlowCardDetail', () => {
  it('labels each block with the tab it comes from, in French and in English', async () => {
    for (const locale of ['fr', 'en'] as const) {
      const wrapper = await mountDetail(tritaniumToBuy(), locale)

      expect(originTexts(wrapper), locale).toEqual(originLabels(locale, SUPPLY_ORIGINS))
    }
  })

  it('shows unknown, never 0, for a stock that cannot be read and for sourcing totals that are null', async () => {
    const stockWrapper = await mountDetail({
      card: flowCard('onSite', { ...NOCXIUM, quantity: 600, source: 'stock', key: 'onSite:38:azbel' }),
      line: bomLineOf({ id: 'line-nocxium', ...NOCXIUM, fulfilledQuantity: 600, containerQuantity: null, containerStockState: 'unknown', reservedQuantity: 7, freeQuantity: 9 }),
    })
    const unknownLabel = localeText('en', `${DETAIL_KEYS}.unknown`)

    expect(originTexts(stockWrapper)).toEqual(originLabels('en', ['useMyStock']))
    expect(squashed(stockWrapper.text())).toContain(unknownLabel)
    expect(squashed(stockWrapper.text())).not.toMatch(STANDALONE_ZERO)

    const sourcingWrapper = await mountDetail({
      card: flowCard('toBuy', { ...NOCXIUM, quantity: 600, key: 'toBuy:38:jita' }),
      line: bomLineOf({ id: 'line-nocxium', ...NOCXIUM, requiredQuantity: 600, remainingQuantity: 600 }),
    })

    expect(squashed(sourcingWrapper.text())).toContain(unknownLabel)
  })

  it('shows the error of one store in its own block and keeps the other blocks', async () => {
    answerApi({ 'material-sourcing': new Error('Sourcing exploded') })

    const wrapper = await mountDetail(tritaniumToBuy())

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.get('[role="alert"]').text()).toContain('Sourcing exploded')
    expect(originTexts(wrapper)).toEqual(originLabels('en', SUPPLY_ORIGINS))
  })

  it('says there is nothing more on a card without a line and closes from the cross button', async () => {
    for (const locale of ['fr', 'en'] as const) {
      const wrapper = await mountDetail({ card: flowCard('unknown', { typeName: 'Unnamed type', key: 'unknown:none' }), line: undefined }, locale)

      expect(wrapper.findAll(ORIGIN)).toHaveLength(0)
      expect(squashed(wrapper.text())).toContain(localeText(locale, `${DETAIL_KEYS}.nothingMore`))
    }

    const wrapper = await mountDetail({ card: flowCard('unknown', { typeName: 'Unnamed type', key: 'unknown:none' }), line: undefined })
    await clickCloseButton(wrapper, 'en')

    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('offers no sales block and reads no sales store for a personal use project', async () => {
    const card = flowCard('out', { ...HAMMERHEAD, quantity: 12, key: 'out:11993:azbel' })
    const line = bomLineOf({ id: 'line-hammerhead', ...HAMMERHEAD, isJob: true, activityType: 'manufacturing', runs: 12 })
    const finalItems = { items: [HAMMERHEAD] }

    const personal = await mountDetail({ card: { ...card, activity: 'manufacturing' }, line, project: projectOf({ ...finalItems, personalUse: true }), canRecordSales: true })
    const personalReads = calledResources()
    vi.mocked(apiRequest).mockClear()
    const shared = await mountDetail({ card: { ...card, activity: 'manufacturing' }, line, project: projectOf(finalItems), canRecordSales: true })

    expect(originTexts(personal)).toEqual(originLabels('en', JOB_ORIGINS))
    expect(personalReads.filter(resource => ['sell-forecast', 'sell-orders', 'sale-suggestions', 'contract-suggestions'].includes(resource))).toEqual([])
    expect(originTexts(shared)).toEqual(originLabels('en', [...JOB_ORIGINS, ...SALES_ORIGINS]))
  })

  it('shows the real content of a purchase block, a linked job block and a sale block', async () => {
    const purchase = await mountDetail(tritaniumToBuy())
    expect(squashed(purchase.text()), 'where to buy').toContain(STRUCTURE_NAME)

    const jobMatch: JobMatch = {
      id: 'match-aria', bomLineId: 'line-ishtar', esiJobId: 480_012, cost: 1_250_000, status: 'active', endDate: '2026-11-23T10:00:00+00:00',
      runs: 7, characterName: 'Aria Solberg', matchedAt: '2026-11-20T08:00:00+00:00', facilityId: 1_040_000_000_002, facilityName: 'Azbel', manual: false,
    }
    const job = await mountDetail({
      card: flowCard('inProduction', { ...ISHTAR, quantity: 12, activity: 'manufacturing', key: 'inProduction:12005:azbel' }),
      line: bomLineOf({ id: 'line-ishtar', ...ISHTAR, isJob: true, activityType: 'manufacturing', runs: 10, jobMatches: [jobMatch] }),
    })
    expect(squashed(job.text()), 'linked job').toContain('Aria Solberg')
    expect(squashed(job.text()), 'linked job runs').toMatch(/(^|\D)7(\D|$)/)

    const sale = await mountDetail({
      card: flowCard('out', { ...HAMMERHEAD, quantity: 12, activity: 'manufacturing', key: 'out:11993:azbel' }),
      line: bomLineOf({ id: 'line-hammerhead', ...HAMMERHEAD, isJob: true, activityType: 'manufacturing', runs: 12 }),
      project: projectOf({ items: [HAMMERHEAD] }),
      canRecordSales: true,
    })
    expect(squashed(sale.text()), 'where to sell').toContain(STRUCTURE_NAME)
    expect(squashed(sale.text()), 'where to sell at Jita').toContain(JITA_MARKET_NAME)
  })
})
