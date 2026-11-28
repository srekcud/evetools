import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiRevenueTab from '@/components/planetary/PiRevenueTab.vue'
import type { PlanetaryProductionProfit, PlanetaryRevenue } from '@/stores/planetary'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { apiRequest } from '@/services/api'
import { PLANNER_ACCOUNT } from '../../planetaryPlannerFixtures'
import { TEMPLATE_B_LINE, TEMPLATE_CATALOG, asFollowed } from '../../planetaryTemplatesFixtures'
import { revenueOf } from '../../planetaryFollowHarness'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const REVENUE: PlanetaryRevenue = {
  periodEnd: '2026-11-17',
  days: 30,
  tiers: [
    { tier: 'P1', realisedIsk: 0, change: null, daily: [], contributors: [] },
    { tier: 'P2', realisedIsk: 4_000_000, change: 12.5, daily: [{ date: '2026-11-16', amount: 4_000_000 }], contributors: [{ characterId: 7, characterName: 'Ilyra Castell', value: 4_000_000 }] },
    { tier: 'P3', realisedIsk: 0, change: null, daily: [], contributors: [] },
    { tier: 'P4', realisedIsk: 0, change: null, daily: [], contributors: [] },
  ],
  realisedIsk: 4_000_000,
  realisedChange: 12.5,
  realisedDaily: [{ date: '2026-11-16', amount: 4_000_000 }],
  realisedContributors: [{ characterId: 7, characterName: 'Ilyra Castell', value: 4_000_000 }],
  estimate: { dailyIsk: 500_000, periodIsk: 15_000_000, contributors: [{ characterId: 7, characterName: 'Ilyra Castell', value: 15_000_000 }] },
  toFix: [
    { characterId: 8, characterName: 'Doran Vex', planetId: 40009081, planetName: 'Perimeter II', solarSystemName: 'Perimeter' },
    { characterId: 8, characterName: 'Doran Vex', planetId: 40009082, planetName: 'Perimeter III', solarSystemName: 'Perimeter' },
  ],
  unsyncedWallets: [],
  sellOrders: { orders: [], charactersToReconnect: [], syncedAt: null },
}

const PRODUCTION_PROFIT: PlanetaryProductionProfit = {
  hub: { structureId: 1049588174021, structureName: 'C-J6MT - 1st Taj Mahgoon (Keepstar)', hasData: true },
  lines: [
    {
      typeId: 9832,
      typeName: 'Coolant',
      tier: 'P2',
      dailyQuantity: 100,
      soldQuantity: 100,
      internal: false,
      hubSellPrice: 700,
      salePrice: 700,
      salesIsk: 70_000,
      inputsIsk: 30_000,
      profitPerDay: 40_000,
      marginPercent: 57.14,
      valuesExtractedAtHubBuy: false,
      jitaIsk: 80_000,
      ifSold: null,
    },
  ],
  salesIsk: 70_000,
  inputsIsk: 30_000,
  profitPerDay: 40_000,
  unknownLineCount: 0,
  taxes: { days: 30, perDay: null },
  netProfitPerDay: 40_000,
  unsyncedWallets: [],
  sources: { purchase: 'paid', sale: 'hub', followedTemplateName: null },
}

const REVENUE_ENDPOINT = '/planetary/revenue'
const PRODUCTION_PROFIT_ENDPOINT = '/planetary/production-profit'
const CONTEXT_ENDPOINTS = ['/planetary/planner', '/planetary/planner/account', '/planetary/templates']

async function mountTab(revenue: PlanetaryRevenue = REVENUE, locale: 'en' | 'fr' = 'en', followedTemplate: PlanTemplate | null = null, revenueOfDays: (days: number) => PlanetaryRevenue = () => revenue, contextError: Error | null = null) {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string) => {
    if (endpoint.startsWith(REVENUE_ENDPOINT)) return revenueOfDays(Number(new URL(endpoint, 'http://localhost').searchParams.get('days')))
    if (endpoint.startsWith(PRODUCTION_PROFIT_ENDPOINT)) return PRODUCTION_PROFIT
    if (contextError !== null) throw contextError
    if (endpoint === '/planetary/templates') return followedTemplate === null ? [] : [followedTemplate]
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    return { id: 'planner', ...TEMPLATE_CATALOG }
  }) as never)
  const wrapper = mount(PiRevenueTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

const UNDERCUT_ORDER = {
  orderId: 5,
  characterName: 'Ilyra Castell',
  typeId: 9832,
  typeName: 'Coolant',
  volumeRemain: 4,
  locationId: 60003760,
  marketName: 'Jita IV - Moon 4',
  price: 1000,
  bestPrice: 900,
  rank: 2,
  state: 'undercut',
  unknownReason: null,
  undercutSince: '2026-11-17T08:00:00Z',
  suggestedPrice: 899.9,
} as const

describe('PiRevenueTab estimate profit', () => {
  it('shows the production profit over the period next to the theoretical production: 40,000 a day over 30 days is 1.20M', async () => {
    const wrapper = await mountTab()
    const profit = wrapper.get('[data-testid="estimate-profit"]')

    expect(profit.text()).toContain('1.20M')
    expect(profit.text()).toContain('30')
    expect(profit.text()).toContain('Paid price')
  })

  it('follows the period: 40,000 a day over 90 days is 3.60M', async () => {
    const wrapper = await mountTab({ ...REVENUE, days: 90 })

    expect(wrapper.get('[data-testid="estimate-profit"]').text()).toContain('3.60M')
  })

  it('states the number of lines without profit instead of counting them as zero', async () => {
    const wrapper = await mountTab()
    const store = (await import('@/stores/planetary')).usePlanetaryStore()
    store.productionProfit = { ...PRODUCTION_PROFIT, unknownLineCount: 2 }
    await flushPromises()

    expect(wrapper.get('[data-testid="estimate-profit"]').text()).toContain('2')
    expect(wrapper.get('[data-testid="estimate-profit"]').attributes('data-incomplete')).toBe('true')
  })
})

describe('PiRevenueTab', () => {
  it('requests the thirty day revenue when it opens and again for another period', async () => {
    const wrapper = await mountTab()

    await wrapper.findAll('button').find(button => button.text() === '90 days')?.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0]).filter(endpoint => endpoint.startsWith(REVENUE_ENDPOINT))).toEqual(['/planetary/revenue?days=30', '/planetary/revenue?days=90'])
  })

  it('loads the follow context, the catalog, the account and the templates, when it opens without waiting for the planner', async () => {
    await mountTab()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0]).filter(endpoint => !endpoint.startsWith(REVENUE_ENDPOINT) && !endpoint.startsWith(PRODUCTION_PROFIT_ENDPOINT)).sort()).toEqual(CONTEXT_ENDPOINTS)
  })

  it('shows no planned against real line while no template is followed', async () => {
    const wrapper = await mountTab()

    expect(wrapper.find('[data-testid="revenue-plan-line"]').exists()).toBe(false)
  })

  it('shows the planned against real line of the followed plan: 120,000 planned a day against 30,000 realised a day over the 30 days', async () => {
    const wrapper = await mountTab({ ...REVENUE, realisedIsk: 900_000 }, 'en', asFollowed(TEMPLATE_B_LINE))

    expect(wrapper.get('[data-testid="revenue-plan-planned"]').text()).toBe('120.00 K')
    expect(wrapper.get('[data-testid="revenue-plan-real"]').text()).toBe('30.00 K')
    expect(wrapper.get('[data-testid="revenue-plan-gap"]').text()).toBe('-90.00 K')
  })

  it('follows the chosen period: 9,000,000 realised over 90 days is 100,000 a day, a gap of -20,000 and a ratio of 0.83', async () => {
    const wrapper = await mountTab(REVENUE, 'en', asFollowed(TEMPLATE_B_LINE), days => ({ ...revenueOf(days === 90 ? 9_000_000 : 900_000, days) }))

    await wrapper.findAll('button').find(button => button.text() === '90 days')?.trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="revenue-plan-real"]').text()).toBe('100.00 K')
    expect(wrapper.get('[data-testid="revenue-plan-gap"]').text()).toBe('-20.00 K')
    expect(wrapper.get('[data-testid="revenue-plan-ratio"]').text()).toBe('0.83')
  })

  it('keeps the revenue grids and shows no planned against real line when the follow context cannot be loaded', async () => {
    const wrapper = await mountTab(REVENUE, 'en', asFollowed(TEMPLATE_B_LINE), () => REVENUE, new Error('context unavailable'))

    expect(wrapper.findAll('[data-testid="revenue-line-grid"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="revenue-plan-line"]').exists()).toBe(false)
  })

  it('loads the production profit once when it opens, whatever the period', async () => {
    const wrapper = await mountTab()

    await wrapper.findAll('button').find(button => button.text() === '90 days')?.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0]).filter(url => url.startsWith(PRODUCTION_PROFIT_ENDPOINT))).toEqual(['/planetary/production-profit'])
  })

  it('shows the production profit grid under the revenue grids', async () => {
    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="production-profit-grid"] [data-testid="profit-line"] [data-cell="profit"]').text()).toBe('40.0k')
    expect(wrapper.findAll('[data-testid="revenue-line-grid"]')).toHaveLength(2)
  })

  it('shows the realised grid with one column per tier, the off-market column as unknown and the net of the sales only', async () => {
    const wrapper = await mountTab()
    const realised = wrapper.findAll('[data-testid="revenue-line-grid"]')[0]

    expect(realised?.findAll('th[data-column]').map(header => header.attributes('data-column'))).toEqual(['P1', 'P2', 'P3', 'P4', 'off-market'])
    expect(realised?.get('[data-cell="off-market"] > div').attributes('data-state')).toBe('unknown')
    expect(realised?.get('[data-testid="net-value"]').text()).toBe('4.00 M')
  })

  it('shows the production estimate in its own grid and never in the net of the sales', async () => {
    const wrapper = await mountTab()
    const grids = wrapper.findAll('[data-testid="revenue-line-grid"]')

    expect(grids).toHaveLength(2)
    expect(grids[1]?.get('[data-cell="estimate"] > div').attributes('data-state')).toBe('estimate')
    expect(grids[1]?.get('[data-testid="net-value"]').text()).toBe('~15.00 M')
    expect(grids[0]?.text()).not.toContain('15.00 M')
  })

  it('lists the colonies producing without any sale per character in the to fix panel', async () => {
    const wrapper = await mountTab()

    expect(wrapper.get('[data-testid="revenue-todo-panel"]').text()).toContain('Doran Vex: 2 producing colonies with no PI sale over the period (Perimeter II, Perimeter III)')
  })

  it('shows no to fix panel when every producing colony sold', async () => {
    const wrapper = await mountTab({ ...REVENUE, toFix: [] })

    expect(wrapper.find('[data-testid="revenue-todo-panel"]').exists()).toBe(false)
  })

  it('shows no wallet column and no wallet item while every wallet is synced', async () => {
    const wrapper = await mountTab()

    expect(wrapper.find('[data-column="wallet-unsynced"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="revenue-todo-panel"]').text()).not.toContain('wallet not synced')
  })

  it('shows a character whose wallet is not synced as an unknown hatched cell outside the net, with a badge, and lists it first in the to fix panel', async () => {
    const wrapper = await mountTab({
      ...REVENUE,
      toFix: [],
      unsyncedWallets: [
        { characterId: 8, characterName: 'Doran Vex', status: 'never_read' },
        { characterId: 9, characterName: 'Mira Tull', status: 'invalid_auth' },
      ],
    })
    const realised = wrapper.findAll('[data-testid="revenue-line-grid"]')[0]
    const items = wrapper.findAll('[data-testid="revenue-todo-panel"] li')

    expect(realised?.get('[data-cell="wallet-unsynced"] > div').attributes('data-state')).toBe('unknown')
    expect(realised?.get('[data-cell="wallet-unsynced"] > div').text()).toBe('—')
    expect(realised?.get('[data-column="wallet-unsynced"] [data-testid="header-badge"]').text()).toBe('2')
    expect(realised?.get('[data-testid="net-value"]').text()).toBe('4.00 M')
    expect(items.map(item => item.get('p').text())).toEqual([
      'Doran Vex: wallet not synced (never read)',
      'Mira Tull: wallet not synced (ESI login expired)',
    ])
  })

  it('puts the wallet items before the colonies without sale', async () => {
    const wrapper = await mountTab({ ...REVENUE, unsyncedWallets: [{ characterId: 9, characterName: 'Mira Tull', status: 'no_token' }] })

    expect(wrapper.findAll('[data-testid="revenue-todo-panel"] li').map(item => item.get('p').text())).toEqual([
      'Mira Tull: wallet not synced (no ESI token)',
      'Doran Vex: 2 producing colonies with no PI sale over the period (Perimeter II, Perimeter III)',
    ])
  })

  it('words the wallet column and item in French', async () => {
    const wrapper = await mountTab({ ...REVENUE, toFix: [], unsyncedWallets: [{ characterId: 8, characterName: 'Doran Vex', status: 'missing_scope' }] }, 'fr')

    expect(wrapper.text()).toContain('Wallet non synchronisé (inconnu)')
    expect(wrapper.get('[data-testid="revenue-todo-panel"]').text()).toContain('Doran Vex : wallet non synchronisé (autorisation wallet manquante)')
  })

  it('labels the tiers and the unknown column in French', async () => {
    const wrapper = await mountTab(REVENUE, 'fr')

    expect(wrapper.text()).toContain('P2 · Raffiné')
    expect(wrapper.text()).toContain('Ventes hors marché (non suivies)')
  })

  it('shows the sell orders of the PI products with their position and the price to copy', async () => {
    const wrapper = await mountTab({ ...REVENUE, sellOrders: { orders: [UNDERCUT_ORDER], charactersToReconnect: [], syncedAt: '2026-11-17T08:05:00Z' } })
    const card = wrapper.get('[data-testid="sell-orders-card"]')

    expect(card.text()).toContain('My PI sell orders')
    expect(card.findAll('[data-testid="sell-order-row"]')).toHaveLength(1)
    expect(card.get('[data-testid="sell-order-position"]').attributes('data-state')).toBe('undercut')
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    await card.get('[data-testid="sell-order-copy-price"]').trigger('click')
    expect(writeText).toHaveBeenCalledWith('899.9')
  })

  it('says there is no PI sell order, not the project wording, when the user sells nothing', async () => {
    const wrapper = await mountTab()
    const card = wrapper.get('[data-testid="sell-orders-card"]')

    expect(card.get('[data-testid="sell-orders-empty"]').text()).toBe('No sell order for the PI products you produce.')
    expect(card.text()).not.toContain('project')
  })

  it('asks to log in again with the characters whose sell orders cannot be read', async () => {
    const wrapper = await mountTab({ ...REVENUE, sellOrders: { orders: [], charactersToReconnect: ['Doran Vex'], syncedAt: null } })

    expect(wrapper.get('[data-testid="sell-orders-reconnect"]').text()).toContain('Doran Vex')
  })

  it('words the PI sell orders card in French', async () => {
    const wrapper = await mountTab(REVENUE, 'fr')

    expect(wrapper.get('[data-testid="sell-orders-card"]').text()).toContain('Mes ordres de vente PI')
    expect(wrapper.get('[data-testid="sell-orders-empty"]').text()).toBe('Aucun ordre de vente pour les produits PI que tu fabriques.')
  })

  it('forwards the request to open the what-to-produce ranking', async () => {
    const wrapper = await mountTab()

    await wrapper.get('[data-testid="profit-open-ranking"]').trigger('click')

    expect(wrapper.emitted('open-planner')).toHaveLength(1)
  })
})
