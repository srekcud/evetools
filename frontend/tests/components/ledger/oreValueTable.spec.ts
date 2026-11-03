import { enableAutoUnmount, mount } from '@vue/test-utils'
import OreValueTable from '@/components/ledger/OreValueTable.vue'
import type { OreValueRow } from '@/composables/useOreValueTable'
import { messageOf } from '../../localeTexts'
import { i18nPlugin } from './miningLedgerHarness'
import {
  BITUMENS_ROW,
  BITUMENS_TYPE_ID,
  CLEAR_ICICLE_ROW,
  CLEAR_ICICLE_TYPE_ID,
  CONCENTRATED_VELDSPAR_TYPE_ID,
  FULLERITE_C50_ROW,
  FULLERITE_C50_TYPE_ID,
  UNPRICED_CONCENTRATED_VELDSPAR_ROW,
  VELDSPAR_ROW,
  VELDSPAR_TYPE_ID,
  oreValueRow,
} from './oreValueTableHarness'

const LONG_NAME_TYPE_ID = 81900
const LONG_NAME_ROW = oreValueRow({
  typeId: LONG_NAME_TYPE_ID,
  typeName: 'Exceptionally Lustrous Hypothetical Moon Ore With An Overly Long Name',
  rawIskPerM3: 52.4,
  bestOption: 'raw',
  bestIskPerM3: 52.4,
  gapToBestPercent: 98.09,
})

function mountTable(rows: OreValueRow[]) {
  return mount(OreValueTable, {
    props: { rows },
    global: { plugins: [i18nPlugin()] },
  })
}

type TableWrapper = ReturnType<typeof mountTable>

function cell(wrapper: TableWrapper, typeId: number, column: 'raw' | 'compressed' | 'reprocessed') {
  return wrapper.get(`tbody tr[data-type-id="${typeId}"]`).get(`td[data-column="${column}"]`)
}

enableAutoUnmount(afterEach)

describe('OreValueTable unknown values', () => {
  it('shows an unknown value with its tooltip when a value per mined m³ is null', () => {
    const wrapper = mountTable([CLEAR_ICICLE_ROW, UNPRICED_CONCENTRATED_VELDSPAR_ROW])

    const unknownReprocessed = cell(wrapper, CLEAR_ICICLE_TYPE_ID, 'reprocessed')
    expect(unknownReprocessed.text()).toBe('—')
    expect(unknownReprocessed.get('[title]').attributes('title')).toBe(messageOf('en', 'common.unknownValue'))
    for (const column of ['raw', 'compressed', 'reprocessed'] as const) {
      const unknownValue = cell(wrapper, CONCENTRATED_VELDSPAR_TYPE_ID, column)
      expect(unknownValue.text()).toBe('—')
      expect(unknownValue.get('[title]').attributes('title')).toBe('Unknown value')
    }
  })

  it('shows a dash without the unknown value tooltip when the type cannot be reprocessed', () => {
    const wrapper = mountTable([FULLERITE_C50_ROW])

    const notReprocessable = cell(wrapper, FULLERITE_C50_TYPE_ID, 'reprocessed')
    expect(notReprocessable.text()).toBe('—')
    expect(notReprocessable.find('[title]').exists()).toBe(false)
    expect(notReprocessable.attributes('title')).toBeUndefined()
  })

  it('shows a value of zero as a known value', () => {
    const wrapper = mountTable([BITUMENS_ROW])

    const zeroReprocessed = cell(wrapper, BITUMENS_TYPE_ID, 'reprocessed')
    expect(zeroReprocessed.text()).not.toBe('—')
    expect(zeroReprocessed.text()).toContain('0')
    expect(zeroReprocessed.find('[title]').exists()).toBe(false)
  })
})

describe('OreValueTable best option', () => {
  it('marks the best option of each row with a text, on its cell only', () => {
    const wrapper = mountTable([FULLERITE_C50_ROW, VELDSPAR_ROW])

    const veldsparBest = cell(wrapper, VELDSPAR_TYPE_ID, 'compressed').find('[data-best-option]')
    expect(veldsparBest.exists()).toBe(true)
    expect(veldsparBest.text().trim()).not.toBe('')
    expect(cell(wrapper, VELDSPAR_TYPE_ID, 'raw').find('[data-best-option]').exists()).toBe(false)
    expect(cell(wrapper, VELDSPAR_TYPE_ID, 'reprocessed').find('[data-best-option]').exists()).toBe(false)
    expect(cell(wrapper, FULLERITE_C50_TYPE_ID, 'raw').find('[data-best-option]').exists()).toBe(true)
    expect(cell(wrapper, FULLERITE_C50_TYPE_ID, 'compressed').find('[data-best-option]').exists()).toBe(false)
  })

  it('marks no best option on a row without any known value', () => {
    const wrapper = mountTable([UNPRICED_CONCENTRATED_VELDSPAR_ROW])

    expect(wrapper.get(`tbody tr[data-type-id="${CONCENTRATED_VELDSPAR_TYPE_ID}"]`).find('[data-best-option]').exists()).toBe(false)
  })
})

describe('OreValueTable rows', () => {
  it('renders one row per type in the given order, long names in full', () => {
    const wrapper = mountTable([FULLERITE_C50_ROW, VELDSPAR_ROW, LONG_NAME_ROW])

    expect(wrapper.findAll('tbody tr[data-type-id]').map(row => row.attributes('data-type-id'))).toEqual([
      String(FULLERITE_C50_TYPE_ID),
      String(VELDSPAR_TYPE_ID),
      String(LONG_NAME_TYPE_ID),
    ])
    expect(wrapper.get(`tbody tr[data-type-id="${LONG_NAME_TYPE_ID}"]`).text()).toContain('Exceptionally Lustrous Hypothetical Moon Ore With An Overly Long Name')
  })
})
