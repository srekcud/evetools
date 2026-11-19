import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PveRevenueGrid from '@/components/revenue/PveRevenueGrid.vue'
import { apiRequest } from '@/services/api'
import type { PveGrid } from '@/stores/pve'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const GRID: PveGrid = {
  period: { from: '2026-11-10', to: '2026-11-17', days: 7 },
  columns: {
    bounties: { value: 500_000_000, previous: 400_000_000, change: 25, daily: { '2026-11-17': 500_000_000 }, contributors: [{ name: 'Main', value: 500_000_000 }] },
    ess: { value: 0, previous: 0, change: null, daily: {}, contributors: [] },
    missions: { value: 0, previous: 0, change: null, daily: {}, contributors: [] },
    lootSales: { value: 100_000_000, previous: 0, change: null, daily: {}, contributors: null },
    escalations: { value: 0, previous: 0, change: null, daily: {}, contributors: null },
    expenses: { value: 60_000_000, previous: 0, change: null, daily: {}, contributors: null },
  },
  net: { value: 540_000_000, previous: 400_000_000, change: 35, daily: { '2026-11-17': 540_000_000 } },
  scans: { lootSales: { lastScanAt: '2026-11-17T09:00:00+00:00', overdue: false }, expenses: { lastScanAt: '2026-11-17T09:00:00+00:00', overdue: false } },
}

const NEVER_SCANNED = { lastScanAt: null, overdue: true }
const SCANNED_LONG_AGO = { lastScanAt: '2026-11-02T09:00:00+00:00', overdue: true }

function gridWithScans(scans: Partial<PveGrid['scans']>): PveGrid {
  return { ...GRID, scans: { ...GRID.scans, ...scans } }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

function mountGrid(props: { days?: number; neverSynced?: boolean; refreshToken?: unknown } = {}, locale: 'en' | 'fr' = 'en') {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(PveRevenueGrid, {
    props: { days: 7, neverSynced: false, ...props },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('PveRevenueGrid', () => {
  it('asks for the grid of the chosen period and shows the PVE sources with the net', async () => {
    vi.mocked(apiRequest).mockResolvedValue(GRID as never)

    const wrapper = mountGrid({ days: 7 })
    await flushPromises()

    expect(apiRequest).toHaveBeenCalledWith('/pve/stats/grid?days=7')
    expect(wrapper.get('[data-cell="bounties"]').text()).toBe('500.00 M')
    expect(wrapper.get('[data-cell="expenses"]').text()).toBe('-60.00 M')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('540.00 M')
    expect(wrapper.get('[data-cell="expenses"] div').attributes('data-state')).toBe('cost')
    expect(wrapper.get('[data-change="bounties"]').text()).toBe('▲ 25 %')
  })

  it('labels the columns in English and in French', async () => {
    vi.mocked(apiRequest).mockResolvedValue(GRID as never)

    const english = mountGrid()
    await flushPromises()
    const french = mountGrid({}, 'fr')
    await flushPromises()

    expect(english.get('thead').text()).toContain('Escalations sold')
    expect(french.get('thead').text()).toContain('Escalations vendues')
    expect(french.get('thead').text()).toContain('Dépenses')
  })

  it('asks again when the period changes or the refresh token changes', async () => {
    vi.mocked(apiRequest).mockResolvedValue(GRID as never)
    const wrapper = mountGrid({ days: 7, refreshToken: 1 })
    await flushPromises()

    await wrapper.setProps({ days: 30 })
    await wrapper.setProps({ refreshToken: 2 })
    await flushPromises()

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toEqual([
      '/pve/stats/grid?days=7',
      '/pve/stats/grid?days=30',
      '/pve/stats/grid?days=30',
    ])
  })

  it('shows the error and no grid when the request fails', async () => {
    vi.mocked(apiRequest).mockRejectedValue(new Error('Boom'))

    const wrapper = mountGrid()
    await flushPromises()

    expect(wrapper.find('[data-testid="revenue-line-grid"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Boom')
  })

  it('shows no To fix panel once the data has been synced', async () => {
    vi.mocked(apiRequest).mockResolvedValue(GRID as never)

    const wrapper = mountGrid({ neverSynced: false })
    await flushPromises()

    expect(wrapper.find('[data-testid="revenue-todo-panel"]').exists()).toBe(false)
  })

  it('asks to refresh while nothing has ever been synced, and emits sync from the action', async () => {
    vi.mocked(apiRequest).mockResolvedValue(GRID as never)

    const wrapper = mountGrid({ neverSynced: true })
    await flushPromises()

    const panel = wrapper.get('[data-testid="revenue-todo-panel"]')
    expect(panel.text()).toContain('No PVE data has been synced yet')
    await panel.get('button').trigger('click')
    expect(wrapper.emitted('sync')).toHaveLength(1)
  })

  it('badges the Loot column and offers the loot scan when it has never run', async () => {
    vi.mocked(apiRequest).mockResolvedValue(gridWithScans({ lootSales: NEVER_SCANNED }) as never)

    const wrapper = mountGrid()
    await flushPromises()

    expect(wrapper.get('[data-column="lootSales"] [data-testid="header-badge"]').text()).toBe('1')
    expect(wrapper.find('[data-column="expenses"] [data-testid="header-badge"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="revenue-todo-panel"]').text()).toContain('Loot sales have never been scanned')
    await wrapper.get('[data-todo="1"] button').trigger('click')
    expect(wrapper.emitted('scanLoot')).toHaveLength(1)
    expect(wrapper.emitted('sync')).toBeUndefined()
  })

  it('badges the Expenses column and offers the purchase scan, naming the date of the last scan', async () => {
    vi.mocked(apiRequest).mockResolvedValue(gridWithScans({ expenses: SCANNED_LONG_AGO }) as never)

    const wrapper = mountGrid()
    await flushPromises()

    expect(wrapper.get('[data-column="expenses"] [data-testid="header-badge"]').text()).toBe('1')
    expect(wrapper.get('[data-testid="revenue-todo-panel"]').text()).toContain('Ammunition purchases not scanned since')
    await wrapper.get('[data-todo="1"] button').trigger('click')
    expect(wrapper.emitted('scanExpenses')).toHaveLength(1)
  })

  it('numbers the scans after the sync item and routes each button to its own action', async () => {
    vi.mocked(apiRequest).mockResolvedValue(gridWithScans({ lootSales: NEVER_SCANNED, expenses: NEVER_SCANNED }) as never)

    const wrapper = mountGrid({ neverSynced: true })
    await flushPromises()

    expect(wrapper.get('[data-column="lootSales"] [data-testid="header-badge"]').text()).toBe('2')
    expect(wrapper.get('[data-column="expenses"] [data-testid="header-badge"]').text()).toBe('3')
    await wrapper.get('[data-todo="1"] button').trigger('click')
    await wrapper.get('[data-todo="2"] button').trigger('click')
    await wrapper.get('[data-todo="3"] button').trigger('click')
    expect(wrapper.emitted('sync')).toHaveLength(1)
    expect(wrapper.emitted('scanLoot')).toHaveLength(1)
    expect(wrapper.emitted('scanExpenses')).toHaveLength(1)
  })

  it('shows no badge and no panel when both scans are recent', async () => {
    vi.mocked(apiRequest).mockResolvedValue(GRID as never)

    const wrapper = mountGrid()
    await flushPromises()

    expect(wrapper.findAll('[data-testid="header-badge"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="revenue-todo-panel"]').exists()).toBe(false)
  })

  it('words the scan items in French', async () => {
    vi.mocked(apiRequest).mockResolvedValue(gridWithScans({ lootSales: NEVER_SCANNED }) as never)

    const wrapper = mountGrid({}, 'fr')
    await flushPromises()

    expect(wrapper.get('[data-testid="revenue-todo-panel"]').text()).toContain('ventes de loot')
  })
})
