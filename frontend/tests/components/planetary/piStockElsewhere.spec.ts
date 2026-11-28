import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetaryStockStore, type PlanetaryStock } from '@/stores/planetaryStock'
import { PLASMA_ROW, SILICON_ROW, stockOf, stockRow } from '../../planetaryStockFixtures'

const ELSEWHERE_ROW = stockRow(SILICON_ROW.typeId, 'Silicon', {
  ...SILICON_ROW,
  home: 1500,
  hub: 0,
  customsOffice: 0,
  elsewhere: 400,
  corp: 0,
  coverageWeeks: 1.9,
  coverageWithOrdersWeeks: 1.9,
  toBuy: 1100,
})

function mountGrid(stock: PlanetaryStock) {
  setActivePinia(createPinia())
  usePlanetaryStockStore().stock = stock
  usePlanetaryPlannerStore().choices = {}
  return mount(PiStockCoverageGrid, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

type Wrapper = ReturnType<typeof mountGrid>

function elsewhereCell(wrapper: Wrapper, typeId: number) {
  return wrapper.get(`[data-testid="stock-cell-elsewhere-${typeId}"]`)
}

function figureOf(wrapper: Wrapper, field: string, typeId: number): string {
  const copy = wrapper.get(`[data-testid="stock-cell-${field}-${typeId}"]`).element.cloneNode(true) as HTMLElement
  copy.querySelectorAll('[data-testid^="stock-pin-"]').forEach(pin => pin.remove())
  return (copy.textContent ?? '').replace(/[^0-9]/g, '')
}

function labelOf(locale: typeof en | typeof fr, key: string): unknown {
  return (locale as unknown as { pi: { stock: { grid: Record<string, unknown> } } }).pi.stock.grid[key]
}

enableAutoUnmount(afterEach)

describe('PiStockCoverageGrid elsewhere column', () => {
  it('shows the elsewhere stock right after the corp hangar, with a visible not-at-the-hub mention in its header', () => {
    const wrapper = mountGrid(stockOf([ELSEWHERE_ROW]))
    const headers = wrapper.findAll('th').map(header => header.text())
    const header = wrapper.get('[data-testid="stock-header-elsewhere"]')

    expect(header.text()).toContain('Elsewhere')
    expect(header.text()).toContain('not at the hub')
    expect(headers.indexOf(header.text())).toBe(headers.indexOf(en.pi.stock.grid.corp) + 1)
  })

  it('shows the quantity as a number flagged not at the hub, apart from the hub figure and its bring-back pin', () => {
    const wrapper = mountGrid(stockOf([ELSEWHERE_ROW]))

    expect(elsewhereCell(wrapper, ELSEWHERE_ROW.typeId).text().replace(/[^0-9]/g, '')).toBe('400')
    expect(elsewhereCell(wrapper, ELSEWHERE_ROW.typeId).attributes('data-not-at-hub')).toBe('true')
    expect(figureOf(wrapper, 'hub', ELSEWHERE_ROW.typeId)).toBe('0')
    expect(wrapper.find(`[data-testid="stock-pin-bringBack-${ELSEWHERE_ROW.typeId}"]`).exists()).toBe(false)
  })

  it('shows a zero without the not-at-the-hub flag when nothing is held elsewhere', () => {
    const wrapper = mountGrid(stockOf([PLASMA_ROW]))

    expect(elsewhereCell(wrapper, PLASMA_ROW.typeId).text()).toBe('0')
    expect(elsewhereCell(wrapper, PLASMA_ROW.typeId).attributes('data-not-at-hub')).toBeUndefined()
  })

  it('keeps the coverage and the quantity to buy computed by the backend', () => {
    const wrapper = mountGrid(stockOf([ELSEWHERE_ROW]))

    expect(wrapper.get(`[data-testid="stock-cell-coverage-${ELSEWHERE_ROW.typeId}"]`).text()).toContain(en.pi.stock.grid.weeksValue.replace('{weeks}', '1.9'))
    expect(figureOf(wrapper, 'toBuy', ELSEWHERE_ROW.typeId)).toBe('1100')
  })

  it('has the column header and its not-at-the-hub mention in English and French', () => {
    for (const locale of [en, fr]) {
      expect(['elsewhere', 'notAtHub'].map(key => typeof labelOf(locale, key) === 'string' && (labelOf(locale, key) as string).length > 0)).toEqual([true, true])
    }
  })
})
