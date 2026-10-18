import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import ShoppingListResults from '@/components/shopping/ShoppingListResults.vue'
import type { ShoppingItem, ShoppingTotals } from '@/components/shopping/ShoppingListResults.vue'
import AppraisalResults from '@/components/shopping/AppraisalResults.vue'
import type { AppraisalItem, AppraisalTotals } from '@/components/shopping/AppraisalResults.vue'

const UNKNOWN_TYPE_ID = 34
const UNKNOWN_TYPE_NAME = 'Mystery Ore'

function mountOptions() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return { global: { plugins: [i18n], stubs: { OpenInGameButton: true } } }
}

function shoppingItem(overrides: Partial<ShoppingItem>): ShoppingItem {
  return {
    typeId: 35,
    typeName: 'Pyerite',
    quantity: 10,
    volume: 2,
    totalVolume: 20,
    jitaPrice: 1000,
    jitaTotal: 10000,
    importCost: 24000,
    jitaWithImport: 34000,
    structurePrice: null,
    structureTotal: null,
    bestLocation: 'jita',
    bestTotal: 34000,
    ...overrides,
  }
}

function appraisalItem(overrides: Partial<AppraisalItem>): AppraisalItem {
  return {
    typeId: 35,
    typeName: 'Pyerite',
    quantity: 10,
    volume: 2,
    totalVolume: 20,
    sellPrice: 1000,
    sellTotal: 10000,
    buyPrice: 900,
    buyTotal: 9000,
    splitPrice: 950,
    splitTotal: 9500,
    sellPriceWeighted: null,
    sellTotalWeighted: null,
    buyPriceWeighted: null,
    buyTotalWeighted: null,
    splitPriceWeighted: null,
    splitTotalWeighted: null,
    sellCoverage: null,
    buyCoverage: null,
    ...overrides,
  }
}

const APPRAISAL_TOTALS: AppraisalTotals = {
  sellTotal: 10000,
  buyTotal: 9000,
  splitTotal: 9500,
  volume: 20,
  sellTotalWeighted: null,
  buyTotalWeighted: null,
  splitTotalWeighted: null,
}

describe('ShoppingListResults with a type of unknown volume', () => {
  const unknownVolumeItem = shoppingItem({
    typeId: UNKNOWN_TYPE_ID,
    typeName: UNKNOWN_TYPE_NAME,
    volume: null,
    totalVolume: null,
    importCost: null,
    jitaWithImport: null,
    bestLocation: null,
    bestTotal: null,
  })
  const totals: ShoppingTotals = { jita: 60000, import: null, jitaWithImport: null, structure: 0, best: 34000, volume: null }

  function mountResults() {
    return mount(ShoppingListResults, {
      ...mountOptions(),
      props: {
        items: [unknownVolumeItem, shoppingItem({})],
        totals,
        structureName: 'C-J6MT - 1st Taj Mahgoon',
        missingVolumeTypeIds: [UNKNOWN_TYPE_ID],
      },
    })
  }

  it('names the type without volume in a warning', () => {
    expect(mountResults().text()).toContain(`Unknown volume in the SDE for: ${UNKNOWN_TYPE_NAME}.`)
  })

  it('shows the total volume and the Jita + import total as unknown, never as 0', () => {
    const summaryValues = mountResults().findAll('.grid p.text-lg').map(node => node.text())
    expect(summaryValues[0]).toBe('Unknown')
    expect(summaryValues[1]).toBe('Unknown')
  })

  it('shows the volume and the Jita + import of the item as unknown while the other item keeps its values', () => {
    const rows = mountResults().findAll('tbody tr')
    const unknownCells = rows[0]?.findAll('td').map(cell => cell.text())
    const knownCells = rows[1]?.findAll('td').map(cell => cell.text())
    expect(unknownCells[2]).toBe('Unknown')
    expect(unknownCells[3]).toBe('Unknown')
    expect(knownCells[2]).toBe('20 m³')
    expect(knownCells[3]).not.toBe('Unknown')
  })
})

describe('AppraisalResults with a type of unknown volume', () => {
  function mountResults(items: AppraisalItem[], totals: AppraisalTotals, missingVolumeTypeIds: number[]) {
    return mount(AppraisalResults, {
      ...mountOptions(),
      props: { items, totals, notFound: [], missingVolumeTypeIds },
    })
  }

  it('shows the item volume and the total volume as unknown and names the type in a warning', () => {
    const wrapper = mountResults(
      [appraisalItem({ typeId: UNKNOWN_TYPE_ID, typeName: UNKNOWN_TYPE_NAME, volume: null, totalVolume: null }), appraisalItem({})],
      { ...APPRAISAL_TOTALS, volume: null },
      [UNKNOWN_TYPE_ID],
    )
    expect(wrapper.text()).toContain(`Unknown volume in the SDE for: ${UNKNOWN_TYPE_NAME}. Their volume and the total volume are unknown.`)
    expect(wrapper.findAll('tbody tr')[0]?.findAll('td')[2]?.text()).toBe('Unknown')
    expect(wrapper.find('tfoot').findAll('td')[2]?.text()).toBe('Unknown')
    expect(wrapper.text()).not.toMatch(/(^|[^\d,.])0 m³/)
  })

  it('renders a shared list saved before the fix as before, without warning', () => {
    const wrapper = mountResults([appraisalItem({})], APPRAISAL_TOTALS, [])
    expect(wrapper.text()).not.toContain('Unknown')
    expect(wrapper.find('tfoot').findAll('td')[2]?.text()).toBe('20 m³')
  })
})
