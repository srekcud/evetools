import { enableAutoUnmount, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import MiningRevenueGrid from '@/components/revenue/MiningRevenueGrid.vue'
import { useLedgerStore, type MiningGrid } from '@/stores/ledger'

const GRID: MiningGrid = {
  period: { from: '2026-11-10', to: '2026-11-17', days: 7 },
  columns: {
    sold: { value: 2_400_000, previous: 1_600_000, change: 50, quantity: 12_000, daily: { '2026-11-17': 2_400_000 }, contributors: [{ id: 1, name: 'Main', value: 2_400_000 }] },
    corp_project: { value: 900_000, previous: 0, change: null, quantity: 4_500, daily: { '2026-11-16': 900_000 }, contributors: [{ id: 2, name: 'Alt', value: 900_000 }] },
    unknown: { value: 100_000, previous: 0, change: null, quantity: 500, daily: {}, contributors: [] },
  },
  net: { value: 3_300_000, previous: 1_600_000, change: 106, daily: { '2026-11-17': 2_400_000, '2026-11-16': 900_000 }, contributors: [{ id: 1, name: 'Main', value: 2_400_000 }, { id: 2, name: 'Alt', value: 900_000 }] },
  missingPriceTypeIds: [],
  corpProjectCountedInPve: false,
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

function mountGrid(grid: MiningGrid | null, missingPriceLabels: string[] = [], locale: 'en' | 'fr' = 'en') {
  const store = useLedgerStore()
  store.miningGrid = grid
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(MiningRevenueGrid, {
    props: { missingPriceLabels },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('MiningRevenueGrid', () => {
  it('shows sold, corp project and uncategorised ore with the net of the first two', () => {
    const wrapper = mountGrid(GRID)

    expect(wrapper.get('[data-cell="sold"]').text()).toBe('2.40 M')
    expect(wrapper.get('[data-cell="corp_project"]').text()).toBe('900.00 K')
    expect(wrapper.get('[data-cell="unknown"]').text()).toBe('100.00 K')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('3.30 M')
    expect(wrapper.get('[data-cell="unknown"] div').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-change="sold"]').text()).toBe('▲ 50 %')
  })

  it('labels the columns in English and in French', () => {
    const english = mountGrid(GRID)
    const french = mountGrid(GRID, [], 'fr')

    expect(english.get('thead').text()).toContain('Uncategorised')
    expect(french.get('thead').text()).toContain('Non classé')
    expect(french.get('thead').text()).toContain('Projet corpo')
  })

  it('names the characters that mined as contributors', () => {
    const wrapper = mountGrid(GRID)

    expect(wrapper.text()).toContain('Main')
    expect(wrapper.text()).toContain('Alt')
  })

  it('shows no To fix panel when every ore is priced and categorised', () => {
    const wrapper = mountGrid({ ...GRID, columns: { ...GRID.columns, unknown: { ...GRID.columns.unknown, quantity: 0 } } })

    expect(wrapper.find('[data-testid="revenue-todo-panel"]').exists()).toBe(false)
  })

  it('asks to categorise the uncategorised ore and emits from the action', async () => {
    const wrapper = mountGrid(GRID)

    const panel = wrapper.get('[data-testid="revenue-todo-panel"]')
    expect(panel.text()).toContain('500 units of ore are not categorised')
    expect(wrapper.get('[data-column="unknown"] [data-testid="header-badge"]').text()).toBe('1')
    await panel.get('button').trigger('click')
    expect(wrapper.emitted('categorise')).toHaveLength(1)
  })

  it('hatches the sold column and names the ores without a price instead of showing 0', () => {
    const wrapper = mountGrid(
      { ...GRID, columns: { ...GRID.columns, sold: { ...GRID.columns.sold, value: null, change: null, daily: null, contributors: null } }, missingPriceTypeIds: [81143] },
      ['Mystery Ore'],
    )

    expect(wrapper.get('[data-cell="sold"]').text()).toContain('—')
    expect(wrapper.get('[data-cell="sold"] div').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-cell="sold"] [data-testid="cell-badge"]').text()).toBe('1')
    expect(wrapper.get('[data-todo="1"]').text()).toContain('Ores without a price: Mystery Ore')
    expect(wrapper.find('[data-todo="1"] a').exists()).toBe(true)
    expect(wrapper.find('[data-todo="2"]').exists()).toBe(true)
  })

  it('links the missing price action to the ore value page', () => {
    const wrapper = mountGrid({ ...GRID, missingPriceTypeIds: [81143] }, ['Mystery Ore'])

    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'mining-ore-value' })
  })

  it('hatches the corp project column when PVE counts it, keeping its value visible', () => {
    const wrapper = mountGrid({ ...GRID, corpProjectCountedInPve: true })

    expect(wrapper.get('[data-cell="corp_project"]').text()).toBe('900.00 K')
    expect(wrapper.get('[data-cell="corp_project"] div').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="net-value"]').text()).toBe('2.40 M')
  })

  it('shows the error and no grid when the revenue could not be read', () => {
    const wrapper = mountGrid(null)
    useLedgerStore().error = 'Boom'

    expect(wrapper.find('[data-testid="revenue-line-grid"]').exists()).toBe(false)
  })
})
