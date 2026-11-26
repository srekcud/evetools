import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import UnknownValue from '@/components/common/UnknownValue.vue'
import PiStockCoverageGrid from '@/components/planetary/PiStockCoverageGrid.vue'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import { usePlanetaryStockStore, type PlanetaryStock } from '@/stores/planetaryStock'
import {
  COOLANT_ROW,
  MECHANICAL_PARTS_ROW,
  NO_HOME_ROW,
  PLASMA_ROW,
  ROBOTICS_ROW,
  STOCK_OF_ALL_KINDS,
  UNKNOWN_EXTRACTION_ROW,
  WATER_ROW,
  stockOf,
  stockRow,
} from '../../planetaryStockFixtures'

const RED_BAR = 'bg-red-500'
const GREEN_BAR = 'bg-emerald-500'

function mountGrid(stock: PlanetaryStock | null, choices: Record<number, 'produce' | 'buy'> = {}) {
  setActivePinia(createPinia())
  usePlanetaryStockStore().stock = stock
  usePlanetaryPlannerStore().choices = choices
  return mount(PiStockCoverageGrid, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })] },
  })
}

type Wrapper = ReturnType<typeof mountGrid>

function cell(wrapper: Wrapper, field: string, typeId: number) {
  return wrapper.get(`[data-testid="stock-cell-${field}-${typeId}"]`)
}

function digitsOf(text: string): string {
  return text.replace(/[^0-9]/g, '')
}

function isUnknown(wrapper: Wrapper, field: string, typeId: number): boolean {
  return cell(wrapper, field, typeId).findComponent(UnknownValue).exists()
}

enableAutoUnmount(afterEach)

describe('PiStockCoverageGrid', () => {
  it('shows one row per type in the order received and the ten columns with their figures', async () => {
    const wrapper = mountGrid(stockOf([PLASMA_ROW, COOLANT_ROW]))

    expect(wrapper.findAll('[data-testid^="stock-row-"]').map(row => row.attributes('data-testid'))).toEqual([
      `stock-row-${PLASMA_ROW.typeId}`,
      `stock-row-${COOLANT_ROW.typeId}`,
    ])
    expect(wrapper.findAll('th').map(header => header.text())).toEqual([
      en.pi.stock.grid.type,
      en.pi.stock.grid.home,
      en.pi.stock.grid.hub,
      en.pi.stock.grid.corp,
      en.pi.stock.grid.weeklyConsumption,
      en.pi.stock.grid.coverage,
      en.pi.stock.grid.openOrders,
      en.pi.stock.grid.coverageWithOrders,
      en.pi.stock.grid.toBuy,
      en.pi.stock.grid.decision,
    ])
    expect(wrapper.get(`[data-testid="stock-row-${PLASMA_ROW.typeId}"]`).text()).toContain('Plasma')
    expect(wrapper.get(`[data-testid="stock-row-${COOLANT_ROW.typeId}"]`).text()).toContain('Coolant')
    expect(['home', 'hub', 'corp', 'weeklyConsumption', 'openOrders', 'toBuy'].map(field => digitsOf(cell(wrapper, field, PLASMA_ROW.typeId).text()))).toEqual(['300', '100', '0', '400', '200', '600'])
    expect(['home', 'hub', 'corp', 'weeklyConsumption', 'openOrders', 'toBuy'].map(field => digitsOf(cell(wrapper, field, COOLANT_ROW.typeId).text()))).toEqual(['2400', '500', '100', '1000', '0', '0'])
    expect(digitsOf(cell(wrapper, 'coverageWithOrders', PLASMA_ROW.typeId).text())).toBe('15')
    expect(digitsOf(cell(wrapper, 'coverageWithOrders', COOLANT_ROW.typeId).text())).toBe('30')
    expect(wrapper.findComponent(UnknownValue).exists()).toBe(false)
  })

  it('shows every unknown figure with the unknown value component and a known zero as a zero', async () => {
    const wrapper = mountGrid(stockOf([UNKNOWN_EXTRACTION_ROW, NO_HOME_ROW]))

    const unknownOfExtraction = ['weeklyConsumption', 'coverage', 'coverageWithOrders', 'toBuy'].filter(field => isUnknown(wrapper, field, UNKNOWN_EXTRACTION_ROW.typeId))
    const knownOfExtraction = ['home', 'hub', 'corp', 'openOrders'].filter(field => !isUnknown(wrapper, field, UNKNOWN_EXTRACTION_ROW.typeId))
    const unknownWithoutHome = ['home', 'corp', 'coverage', 'coverageWithOrders', 'toBuy'].filter(field => isUnknown(wrapper, field, NO_HOME_ROW.typeId))
    const knownWithoutHome = ['hub', 'weeklyConsumption', 'openOrders'].filter(field => !isUnknown(wrapper, field, NO_HOME_ROW.typeId))

    expect(unknownOfExtraction).toEqual(['weeklyConsumption', 'coverage', 'coverageWithOrders', 'toBuy'])
    expect(knownOfExtraction).toEqual(['home', 'hub', 'corp', 'openOrders'])
    expect(unknownWithoutHome).toEqual(['home', 'corp', 'coverage', 'coverageWithOrders', 'toBuy'])
    expect(knownWithoutHome).toEqual(['hub', 'weeklyConsumption', 'openOrders'])
    expect(cell(wrapper, 'corp', UNKNOWN_EXTRACTION_ROW.typeId).text()).toBe('0')
    expect(cell(wrapper, 'openOrders', NO_HOME_ROW.typeId).text()).toBe('0')
    expect(digitsOf(cell(wrapper, 'hub', NO_HOME_ROW.typeId).text())).toBe('80')
    expect(wrapper.findAllComponents(UnknownValue)).toHaveLength(9)
  })

  it('writes the coverage in weeks with one decimal, trailing zero included and rounded, for a known coverage', async () => {
    const roundedUp = stockRow(ROBOTICS_ROW.typeId, 'Robotics', { weeklyConsumption: 933, home: 1500, hub: 500, coverageWeeks: 2000 / 933, coverageWithOrdersWeeks: 2000 / 933 })
    const wrapper = mountGrid(stockOf([roundedUp, COOLANT_ROW, WATER_ROW]))

    expect(cell(wrapper, 'coverage', ROBOTICS_ROW.typeId).text()).toContain(en.pi.stock.grid.weeksValue.replace('{weeks}', '2.1'))
    expect(cell(wrapper, 'coverage', COOLANT_ROW.typeId).text()).toContain(en.pi.stock.grid.weeksValue.replace('{weeks}', '3.0'))
    expect(cell(wrapper, 'coverage', WATER_ROW.typeId).text()).toContain(en.pi.stock.grid.weeksValue.replace('{weeks}', '4.5'))
  })

  it('draws the coverage bar red below the target of weeks and not red at the target or above', async () => {
    const wrapper = mountGrid(STOCK_OF_ALL_KINDS)
    const barOf = (typeId: number) => wrapper.get(`[data-testid="stock-coverage-bar-${typeId}"]`)

    expect(barOf(ROBOTICS_ROW.typeId).classes()).toContain(RED_BAR)
    expect(barOf(PLASMA_ROW.typeId).classes()).toContain(RED_BAR)
    expect(barOf(MECHANICAL_PARTS_ROW.typeId).classes()).toContain(RED_BAR)
    expect(barOf(COOLANT_ROW.typeId).classes()).not.toContain(RED_BAR)
    expect(barOf(COOLANT_ROW.typeId).classes()).toContain(GREEN_BAR)
    expect(barOf(WATER_ROW.typeId).classes()).not.toContain(RED_BAR)
    expect(barOf(WATER_ROW.typeId).classes()).toContain(GREEN_BAR)
    expect(barOf(MECHANICAL_PARTS_ROW.typeId).classes()).not.toContain(GREEN_BAR)
  })

  it('shows Make or Buy from the planner choice, a plain dash without choice, and a button in each decision cell that asks for the planner', async () => {
    const wrapper = mountGrid(stockOf([ROBOTICS_ROW, PLASMA_ROW, COOLANT_ROW]), { [ROBOTICS_ROW.typeId]: 'produce', [PLASMA_ROW.typeId]: 'buy' })

    expect(cell(wrapper, 'decision', ROBOTICS_ROW.typeId).text()).toContain(en.pi.stock.grid.produce)
    expect(cell(wrapper, 'decision', ROBOTICS_ROW.typeId).text()).not.toContain(en.pi.stock.grid.buy)
    expect(cell(wrapper, 'decision', PLASMA_ROW.typeId).text()).toContain(en.pi.stock.grid.buy)
    expect(cell(wrapper, 'decision', PLASMA_ROW.typeId).text()).not.toContain(en.pi.stock.grid.produce)
    expect(cell(wrapper, 'decision', COOLANT_ROW.typeId).text()).not.toContain(en.pi.stock.grid.produce)
    expect(cell(wrapper, 'decision', COOLANT_ROW.typeId).text()).not.toContain(en.pi.stock.grid.buy)
    expect(isUnknown(wrapper, 'decision', COOLANT_ROW.typeId)).toBe(false)
    expect(cell(wrapper, 'decision', COOLANT_ROW.typeId).text().replace(en.pi.stock.grid.openPlanner, '').trim()).toBe('—')
    for (const row of [ROBOTICS_ROW, PLASMA_ROW, COOLANT_ROW]) {
      expect(cell(wrapper, 'decision', row.typeId).find(`[data-testid="stock-open-planner-${row.typeId}"]`).exists()).toBe(true)
    }
    expect(wrapper.emitted('open-planner')).toBeUndefined()

    await wrapper.get(`[data-testid="stock-open-planner-${COOLANT_ROW.typeId}"]`).trigger('click')
    await wrapper.get(`[data-testid="stock-open-planner-${ROBOTICS_ROW.typeId}"]`).trigger('click')

    expect(wrapper.emitted('open-planner')).toHaveLength(2)
  })

  it('shows the grid without any row when nothing is built, and before the stock is read', async () => {
    const nothingBuilt = mountGrid(stockOf([]))
    const notLoaded = mountGrid(null)

    expect(nothingBuilt.find('[data-testid="pi-stock-grid"]').exists()).toBe(true)
    expect(nothingBuilt.findAll('[data-testid^="stock-row-"]')).toHaveLength(0)
    expect(notLoaded.findAll('[data-testid^="stock-row-"]')).toHaveLength(0)
  })
})
