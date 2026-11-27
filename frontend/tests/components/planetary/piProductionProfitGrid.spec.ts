import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiProductionProfitGrid from '@/components/planetary/PiProductionProfitGrid.vue'
import type { PlanetaryProductionProfit, ProductionProfitLine } from '@/stores/planetary'

function line(overrides: Partial<ProductionProfitLine>): ProductionProfitLine {
  return {
    typeId: 9832,
    typeName: 'Coolant',
    tier: 'P2',
    dailyQuantity: 10_000,
    soldQuantity: 10_000,
    internal: false,
    hubSellPrice: 700,
    salesIsk: 7_000_000,
    inputsIsk: 3_080_000,
    profitPerDay: 3_920_000,
    marginPercent: 56,
    valuesExtractedAtHubBuy: false,
    jitaIsk: 8_000_000,
    ifSold: null,
    ...overrides,
  }
}

function profit(overrides: Partial<PlanetaryProductionProfit> = {}): PlanetaryProductionProfit {
  return {
    hub: { structureId: 1049588174021, structureName: 'C-J6MT - 1st Taj Mahgoon (Keepstar)', hasData: true },
    lines: [line({})],
    salesIsk: 7_000_000,
    inputsIsk: 3_080_000,
    profitPerDay: 3_920_000,
    unknownLineCount: 0,
    taxes: { days: 30, perDay: 100_000 },
    netProfitPerDay: 3_820_000,
    unsyncedWallets: [],
    ...overrides,
  }
}

function mountGrid(data: PlanetaryProductionProfit, locale: 'en' | 'fr' = 'en') {
  return mount(PiProductionProfitGrid, {
    props: { profit: data },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
}

enableAutoUnmount(afterEach)

describe('PiProductionProfitGrid', () => {
  it('shows a known line with its sales at the hub, its inputs, its profit and its margin', () => {
    const wrapper = mountGrid(profit())
    const row = wrapper.get('[data-testid="profit-line"][data-type-id="9832"]')

    expect(row.get('[data-cell="sold"]').text()).toBe('10.0k')
    expect(row.get('[data-cell="sales"]').text()).toBe('7.00M')
    expect(row.get('[data-cell="inputs"]').text()).toBe('3.08M')
    expect(row.get('[data-cell="profit"]').text()).toBe('3.92M')
    expect(row.get('[data-cell="margin"]').text()).toBe('56 %')
    expect(row.get('[data-cell="jita"]').text()).toBe('8.00M')
  })

  it('shows an unknown line as unknown and never as zero', () => {
    const wrapper = mountGrid(profit({
      lines: [line({ inputsIsk: null, profitPerDay: null, marginPercent: null })],
      salesIsk: 0,
      inputsIsk: 0,
      profitPerDay: 0,
      unknownLineCount: 1,
      netProfitPerDay: -100_000,
    }))
    const row = wrapper.get('[data-testid="profit-line"]')

    expect(row.get('[data-cell="profit"]').attributes('data-state')).toBe('unknown')
    expect(row.get('[data-cell="profit"]').text()).toBe('unknown')
    expect(row.get('[data-cell="inputs"]').attributes('data-state')).toBe('unknown')
    expect(row.get('[data-cell="margin"]').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="profit-unknown-count"]').text()).toContain('1 line unknown')
  })

  it('shows an internal step without margin and says its margin is counted on the final product', () => {
    const wrapper = mountGrid(profit({
      lines: [line({ typeId: 2390, typeName: 'Plasmoids', tier: 'P1', soldQuantity: 0, internal: true, salesIsk: 0, inputsIsk: null, profitPerDay: null, marginPercent: null, jitaIsk: 0 })],
    }))
    const row = wrapper.get('[data-testid="profit-line"]')

    expect(row.get('[data-cell="profit"]').attributes('data-state')).toBe('internal')
    expect(row.get('[data-cell="profit"]').text()).toBe('Internal step, margin counted on the final product')
  })

  it('shows the margin an internal step would have if sold next to the current wording, and none on a sold line', () => {
    const internal = line({
      typeId: 2390,
      typeName: 'Plasmoids',
      tier: 'P1',
      soldQuantity: 0,
      internal: true,
      salesIsk: 0,
      inputsIsk: null,
      profitPerDay: null,
      marginPercent: null,
      jitaIsk: 0,
      ifSold: { salesIsk: 2_000_000, inputsIsk: 800_000, profitPerDay: 1_200_000, marginPercent: 60 },
    })
    const wrapper = mountGrid(profit({ lines: [line({}), internal] }))

    const internalRow = wrapper.get('[data-testid="profit-line"][data-type-id="2390"]')
    expect(internalRow.get('[data-cell="profit"]').text()).toBe('Internal step, margin counted on the final product')
    const ifSold = internalRow.get('[data-testid="profit-if-sold"]').text()
    expect(ifSold).toContain('1.20M')
    expect(ifSold).toContain('60 %')
    expect(wrapper.get('[data-testid="profit-line"][data-type-id="9832"]').find('[data-testid="profit-if-sold"]').exists()).toBe(false)
  })

  it('shows an unknown margin if sold as unknown and never as zero', () => {
    const internal = line({
      typeId: 2390,
      internal: true,
      soldQuantity: 0,
      salesIsk: 0,
      inputsIsk: null,
      profitPerDay: null,
      marginPercent: null,
      ifSold: { salesIsk: null, inputsIsk: 800_000, profitPerDay: null, marginPercent: null },
    })
    const wrapper = mountGrid(profit({ lines: [internal] }))

    const ifSold = wrapper.get('[data-testid="profit-if-sold"]')
    expect(ifSold.attributes('data-state')).toBe('unknown')
    expect(ifSold.text()).toContain('unknown')
    expect(ifSold.text()).not.toContain('0 %')
  })

  it('opens the what-to-produce ranking from the grid', async () => {
    const wrapper = mountGrid(profit())

    await wrapper.get('[data-testid="profit-open-ranking"]').trigger('click')

    expect(wrapper.emitted('open-planner')).toHaveLength(1)
  })

  it('shows the known total, the taxes per day and the net profit', () => {
    const wrapper = mountGrid(profit())

    expect(wrapper.get('[data-testid="profit-total"]').text()).toContain('3.92M')
    expect(wrapper.get('[data-testid="profit-taxes"]').text()).toContain('100.0k')
    expect(wrapper.get('[data-testid="profit-net"]').text()).toContain('3.82M')
  })

  it('shows the taxes as unknown when no tax line was read, not as zero', () => {
    const wrapper = mountGrid(profit({ taxes: { days: 30, perDay: null }, netProfitPerDay: 3_920_000 }))

    expect(wrapper.get('[data-testid="profit-taxes"]').attributes('data-state')).toBe('unknown')
    expect(wrapper.get('[data-testid="profit-taxes"]').text()).toContain('unknown')
  })

  it('labels the inputs extracted by the player as valued at the hub buy price, only when one enters a line', () => {
    const without = mountGrid(profit())
    const withExtracted = mountGrid(profit({ lines: [line({ valuesExtractedAtHubBuy: true })] }))

    expect(without.find('[data-testid="profit-extracted-note"]').exists()).toBe(false)
    expect(withExtracted.get('[data-testid="profit-extracted-note"]').text()).toBe('Inputs you extract are valued at the hub buy price (what buying them at the hub would cost).')
  })

  it('names the hub and warns when its market was never synced', () => {
    const wrapper = mountGrid(profit({ hub: { structureId: 1, structureName: 'Own Hub', hasData: false } }))

    expect(wrapper.get('[data-testid="profit-hub"]').text()).toContain('Own Hub')
    expect(wrapper.find('[data-testid="profit-no-hub-data"]').exists()).toBe(true)
  })

  it('shows an empty state without any production line', () => {
    const wrapper = mountGrid(profit({ lines: [], salesIsk: 0, inputsIsk: 0, profitPerDay: 0, taxes: { days: 30, perDay: null }, netProfitPerDay: 0 }))

    expect(wrapper.find('[data-testid="profit-line"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="profit-empty"]').text()).toBe('No production to value yet')
  })

  it('words the grid in French', () => {
    const wrapper = mountGrid(profit({ lines: [line({ profitPerDay: null, inputsIsk: null, marginPercent: null, valuesExtractedAtHubBuy: true })], unknownLineCount: 1 }), 'fr')

    expect(wrapper.text()).toContain('Bénéfice de production par jour')
    expect(wrapper.get('[data-testid="profit-line"] [data-cell="profit"]').text()).toBe('inconnu')
    expect(wrapper.get('[data-testid="profit-extracted-note"]').text()).toContain('prix d’achat du hub')
  })
})
