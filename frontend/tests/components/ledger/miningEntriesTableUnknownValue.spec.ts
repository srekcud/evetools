import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MiningEntriesTable from '@/components/ledger/MiningEntriesTable.vue'
import type { MiningEntry } from '@/stores/ledger'
import {
  ASTRAHUS_STRUCTURE_ID,
  MYSTERY_ORE_TYPE_ID,
  SCORDITE_TYPE_ID,
  VELDSPAR_TYPE_ID,
  i18nPlugin,
  miningEntry,
} from './miningLedgerHarness'

const STRUCTURE_COMPRESSED_VELDSPAR = miningEntry({ id: 'structure-compressed-veldspar', typeId: VELDSPAR_TYPE_ID, typeName: 'Veldspar', quantity: 3_000, structureCompressedUnitPrice: 1_100, structureCompressedEquivalentPrice: 1_100, reprocessValue: 13.32, usage: 'sold' })
const REPROCESSED_SCORDITE = miningEntry({ id: 'reprocessed-scordite', typeId: SCORDITE_TYPE_ID, typeName: 'Scordite', quantity: 4_000, reprocessValue: 22.5, usage: 'corp_project' })
const UNPRICED_MYSTERY_ORE = miningEntry({ id: 'mystery-ore', typeId: MYSTERY_ORE_TYPE_ID, typeName: 'Mystery Ore', quantity: 3_150, usage: 'industry' })

function mountTable(entries: MiningEntry[]) {
  setActivePinia(createPinia())
  return mount(MiningEntriesTable, {
    props: { entries, selectedStructureId: ASTRAHUS_STRUCTURE_ID, reprocessYield: 78 },
    global: { plugins: [i18nPlugin()] },
  })
}

type TableWrapper = ReturnType<typeof mountTable>

function cell(wrapper: TableWrapper, rowIndex: number, header: string) {
  const headers = wrapper.findAll('thead th').map(th => th.text())
  const columnIndex = headers.indexOf(header)
  if (columnIndex === -1) throw new Error(`No column ${header}`)
  return wrapper.findAll('tbody tr')[rowIndex].findAll('td')[columnIndex]
}

enableAutoUnmount(afterEach)

describe('MiningEntriesTable structure column', () => {
  it('shows the compressed equivalent price of the structure without dividing it', () => {
    const wrapper = mountTable([STRUCTURE_COMPRESSED_VELDSPAR])

    expect(cell(wrapper, 0, 'Structure').text()).toBe('1,100')
  })
})

describe('MiningEntriesTable total column', () => {
  it('values an entry at its best unit price, the compressed equivalent price of the structure, times its quantity', () => {
    const wrapper = mountTable([STRUCTURE_COMPRESSED_VELDSPAR])

    expect(cell(wrapper, 0, 'Total').text()).toBe('3.30 M')
  })

  it('shows an unknown value for an ore without any price, next to known entries', () => {
    const wrapper = mountTable([REPROCESSED_SCORDITE, UNPRICED_MYSTERY_ORE])

    expect(cell(wrapper, 0, 'Total').text()).toBe('90.00 K')
    const unknownTotal = cell(wrapper, 1, 'Total')
    expect(unknownTotal.text()).toBe('—')
    expect(unknownTotal.get('[title]').attributes('title')).toBe('Unknown value')
  })
})
