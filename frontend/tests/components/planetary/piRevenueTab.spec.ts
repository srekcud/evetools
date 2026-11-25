import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiRevenueTab from '@/components/planetary/PiRevenueTab.vue'
import type { PlanetaryRevenue } from '@/stores/planetary'
import { apiRequest } from '@/services/api'

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

async function mountTab(revenue: PlanetaryRevenue = REVENUE, locale: 'en' | 'fr' = 'en') {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue(revenue as never)
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

describe('PiRevenueTab', () => {
  it('requests the thirty day revenue when it opens and again for another period', async () => {
    const wrapper = await mountTab()

    await wrapper.findAll('button').find(button => button.text() === '90 days')?.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual(['/planetary/revenue?days=30', '/planetary/revenue?days=90'])
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
})
