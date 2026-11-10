import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AppraisalResultsTable from '@/components/appraisal/AppraisalResultsTable.vue'
import type { MergedItem } from '@/components/appraisal/AppraisalResultsTable.vue'
import type { AppraisalPriceSources } from '@/stores/industry/types'

const TRITANIUM_TYPE_ID = 34
const MEXALLON_TYPE_ID = 36

const TRITANIUM_PRICE_SOURCES: AppraisalPriceSources = {
  sell: { market: 'jita', side: 'sell', method: 'lowest', coveragePercent: null },
  buy: { market: 'jita', side: 'buy', method: 'lowest', coveragePercent: null },
  sellWeighted: { market: 'jita', side: 'sell', method: 'weighted', coveragePercent: 75 },
  buyWeighted: { market: 'jita', side: 'buy', method: 'weighted', coveragePercent: null },
}

const UNKNOWN_PRICE_SOURCES: AppraisalPriceSources = { sell: null, buy: null, sellWeighted: null, buyWeighted: null }

function mergedItem(overrides: Partial<MergedItem>): MergedItem {
  return {
    typeId: TRITANIUM_TYPE_ID,
    typeName: 'Tritanium',
    quantity: 100,
    volume: 0.01,
    totalVolume: 1,
    sellPrice: 5,
    sellPriceWeighted: 5.5,
    sellTotal: 500,
    sellTotalWeighted: 550,
    sellCoverage: 0.75,
    buyPrice: 4,
    buyPriceWeighted: 4.2,
    buyTotal: 400,
    buyTotalWeighted: 420,
    buyCoverage: 1,
    avgDailyVolume: null,
    jitaWithImport: null,
    structureTotal: null,
    structureCoverage: null,
    structureCoverageQty: null,
    bestLocation: null,
    priceSources: TRITANIUM_PRICE_SOURCES,
    ...overrides,
  }
}

const TRITANIUM = mergedItem({})
const UNPRICED_MEXALLON = mergedItem({
  typeId: MEXALLON_TYPE_ID,
  typeName: 'Mexallon',
  sellPrice: null,
  sellPriceWeighted: null,
  sellTotal: null,
  sellTotalWeighted: null,
  sellCoverage: null,
  buyPrice: null,
  buyPriceWeighted: null,
  buyTotal: null,
  buyTotalWeighted: null,
  buyCoverage: null,
  priceSources: UNKNOWN_PRICE_SOURCES,
})
const TRITANIUM_FROM_AN_OLD_RESPONSE = mergedItem({ priceSources: undefined })

function mountTable(items: MergedItem[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(AppraisalResultsTable, {
    global: { plugins: [i18n], stubs: { OpenInGameButton: true } },
    props: {
      items,
      hasStructureResults: false,
      shortStructureName: 'C-J6MT',
      itemCount: items.length,
      jitaItemCount: items.length,
      structureItemCount: 0,
      totalVolume: 1,
      sellValue: 550,
      sellValueBest: 500,
      buyValue: 420,
      buyValueBest: 400,
      jitaPlusTransport: null,
      structureTotal: null,
      bestPrice: null,
      lowCoverageItems: 0,
      hasSellTotalWeighted: true,
      hasBuyTotalWeighted: true,
    },
  })
}

function headerOf(wrapper: ReturnType<typeof mountTable>, title: string): string {
  const header = wrapper.findAll('thead th').find(candidate => candidate.text().startsWith(title))
  expect(header, `column ${title}`).toBeDefined()
  return header!.text()
}

function rowCells(wrapper: ReturnType<typeof mountTable>, rowIndex: number): string[] {
  return wrapper.findAll('tbody tr')[rowIndex]!.findAll('td').map(cell => cell.text())
}

describe('AppraisalResultsTable price sources', () => {
  it('labels the unit price and weighted total columns with the market, side and method of their prices', () => {
    const wrapper = mountTable([TRITANIUM])

    const unitPriceHeader = headerOf(wrapper, 'Unit Price')
    expect(unitPriceHeader).toContain('Jita · lowest')
    expect(unitPriceHeader).toContain('Jita buy · lowest')
    const weightedTotalHeader = headerOf(wrapper, 'Weighted Total')
    expect(weightedTotalHeader).toContain('Jita · weighted')
    expect(weightedTotalHeader).toContain('Jita buy · weighted')
  })

  it('marks the partial coverage of the weighted sell price of a row once, on the weighted total', () => {
    const cells = rowCells(mountTable([TRITANIUM]), 0)

    expect(cells[4]!.match(/75% covered/g) ?? []).toHaveLength(1)
    expect(cells[3]).not.toContain('75% covered')
  })

  it('shows an unpriced item as a dash with no label and no zero', () => {
    const wrapper = mountTable([TRITANIUM, UNPRICED_MEXALLON])

    const cells = rowCells(wrapper, 1)
    expect(cells[3]).toBe('-')
    expect(cells[4]).toBe('-')
    expect(cells[3]! + cells[4]!).not.toContain('·')
    expect(cells[3]! + cells[4]!).not.toContain('0')
  })

  it('shows the prices without label nor error when the response carries no price sources', () => {
    const wrapper = mountTable([TRITANIUM_FROM_AN_OLD_RESPONSE])

    expect(wrapper.text()).toContain('5.5')
    expect(wrapper.text()).not.toContain('·')
    expect(wrapper.text()).not.toContain('covered')
  })
})
