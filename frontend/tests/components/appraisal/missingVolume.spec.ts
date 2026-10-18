import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import AppraisalResultsTable from '@/components/appraisal/AppraisalResultsTable.vue'
import type { MergedItem } from '@/components/appraisal/AppraisalResultsTable.vue'
import AppraisalActions from '@/components/appraisal/AppraisalActions.vue'

const VOLUME_COLUMN = 2
const JITA_WITH_IMPORT_COLUMN = 5

function mountOptions() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return { global: { plugins: [i18n], stubs: { OpenInGameButton: true } } }
}

function mergedItem(overrides: Partial<MergedItem>): MergedItem {
  return {
    typeId: 35,
    typeName: 'Pyerite',
    quantity: 10,
    volume: 2,
    totalVolume: 20,
    sellPrice: 1000,
    sellPriceWeighted: null,
    sellTotal: 10000,
    sellTotalWeighted: null,
    sellCoverage: null,
    buyPrice: 900,
    buyPriceWeighted: null,
    buyTotal: 9000,
    buyTotalWeighted: null,
    buyCoverage: null,
    avgDailyVolume: null,
    jitaWithImport: 34000,
    structureTotal: null,
    structureCoverage: null,
    structureCoverageQty: null,
    bestLocation: 'jita',
    ...overrides,
  }
}

const UNKNOWN_VOLUME_ITEM = mergedItem({ typeId: 34, typeName: 'Mystery Ore', volume: null, totalVolume: null, jitaWithImport: null, bestLocation: null })
const UNPRICED_ITEM = mergedItem({ typeId: 36, typeName: 'Mexallon', sellPrice: null, sellTotal: null, buyPrice: null, buyTotal: null, jitaWithImport: null, bestLocation: null })

describe('AppraisalResultsTable with a type of unknown volume', () => {
  function mountTable(totalVolume: number | null, jitaPlusTransport: number | null) {
    return mount(AppraisalResultsTable, {
      ...mountOptions(),
      props: {
        items: [UNKNOWN_VOLUME_ITEM, mergedItem({}), UNPRICED_ITEM],
        hasStructureResults: true,
        shortStructureName: 'C-J6MT',
        itemCount: 3,
        jitaItemCount: 3,
        structureItemCount: 0,
        totalVolume,
        sellValue: 10000,
        sellValueBest: 10000,
        buyValue: 9000,
        buyValueBest: 9000,
        jitaPlusTransport,
        structureTotal: null,
        bestPrice: null,
        lowCoverageItems: 0,
        hasSellTotalWeighted: false,
        hasBuyTotalWeighted: false,
      },
    })
  }

  function rowCells(wrapper: ReturnType<typeof mountTable>, rowIndex: number): string[] {
    return wrapper.findAll('tbody tr')[rowIndex]!.findAll('td').map(cell => cell.text())
  }

  it('shows the volume and the Jita + import of the item as unknown', () => {
    const cells = rowCells(mountTable(null, null), 0)
    expect(cells[VOLUME_COLUMN]).toBe('Unknown')
    expect(cells[JITA_WITH_IMPORT_COLUMN]).toBe('Unknown')
  })

  it('shows the known volume of the other item in cubic meters', () => {
    expect(rowCells(mountTable(null, null), 1)[VOLUME_COLUMN]).toBe('20 m3')
  })

  it('shows a dash and not unknown for the Jita + import of an item with a known volume and no Jita price', () => {
    expect(rowCells(mountTable(null, null), 2)[JITA_WITH_IMPORT_COLUMN]).toBe('-')
  })

  it('shows the total volume and the Jita + import total as unknown, never as 0', () => {
    const footerCells = mountTable(null, null).find('tfoot').findAll('td').map(cell => cell.text())
    expect(footerCells[VOLUME_COLUMN]).toBe('Unknown')
    expect(footerCells[JITA_WITH_IMPORT_COLUMN]).toBe('Unknown')
  })

  it('shows the total volume and the Jita + import total when they are known', () => {
    const footerCells = mountTable(40, 68000).find('tfoot').findAll('td').map(cell => cell.text())
    expect(footerCells[VOLUME_COLUMN]).toBe('40 m3')
    expect(footerCells[JITA_WITH_IMPORT_COLUMN]).not.toBe('Unknown')
  })
})

describe('AppraisalActions table copy with a type of unknown volume', () => {
  it('copies the volume and the Jita + import of the item as unknown and a dash for an unpriced item', async () => {
    const writeText = vi.fn()
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
    const wrapper = mount(AppraisalActions, {
      ...mountOptions(),
      props: {
        mergedItems: [UNKNOWN_VOLUME_ITEM, mergedItem({}), UNPRICED_ITEM],
        hasStructureResults: false,
        shortStructureName: 'C-J6MT',
        jitaItemCount: 3,
        structureItemCount: 0,
        isSharing: false,
        shareUrl: null,
      },
    })

    await wrapper.findAll('button').find(button => button.text() === 'Copy table')!.trigger('click')

    const rows = (writeText.mock.calls[0]![0] as string).split('\n').map(row => row.split('\t'))
    expect(rows[1]![VOLUME_COLUMN]).toBe('Unknown')
    expect(rows[1]![JITA_WITH_IMPORT_COLUMN]).toBe('Unknown')
    expect(rows[2]![VOLUME_COLUMN]).toBe('20 m3')
    expect(rows[3]![JITA_WITH_IMPORT_COLUMN]).toBe('-')
  })
})
