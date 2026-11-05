import { mount, type DOMWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import VenueComparisonTable, { type SellRow } from '@/components/industry/profit/VenueComparisonTable.vue'
import { expectedMessage } from '../../expectedMessage'
import type { TestLocale } from '../../localeTexts'

const UNKNOWN_FEES_KEY = 'industry.fees.unknown'
const UNKNOWN_FEES_REASON_KEY = 'industry.fees.unknownReason'

const JITA_SELL_ROW_WITH_UNKNOWN_FEES: SellRow = {
  key: 'jitaSell',
  venue: 'Jita 4-4',
  tag: 'sell',
  unitPrice: 61.2,
  revenue: 1_836_000,
  fees: null,
  profit: null,
  margin: null,
  dailyVolume: 125_000,
  contractCount: null,
  isBest: false,
}

const CONTRACT_ROW: SellRow = {
  key: 'contractSell',
  venue: 'The Forge',
  tag: 'contract',
  unitPrice: 58,
  revenue: 1_740_000,
  fees: 0,
  profit: 118_000,
  margin: 7.27,
  dailyVolume: null,
  contractCount: 4,
  isBest: true,
}

const JITA_SELL_ROW_WITH_KNOWN_FEES: SellRow = {
  ...JITA_SELL_ROW_WITH_UNKNOWN_FEES,
  fees: 137_700,
  profit: 131_972,
  margin: 8.43,
}

function cellOf(row: DOMWrapper<Element> | undefined, index: number): DOMWrapper<Element> {
  const cell = row?.findAll('td')[index]
  if (cell === undefined) throw new Error(`no cell ${index}`)
  return cell
}

function unknownValueTitle(cell: DOMWrapper<Element>): string | null {
  const marker = cell.find('span[title]')
  return marker.exists() ? (marker.attributes('title') ?? null) : null
}

function mountVenueComparison(locale: TestLocale, rows: SellRow[], brokerFeeRate: number | null) {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(VenueComparisonTable, {
    global: { plugins: [i18n] },
    props: { rows, runs: 3, brokerFeeRate },
  })
}

describe('VenueComparisonTable with unknown seller fees', () => {
  it('shows the unknown fees state with the sync prompt and unknown cells instead of 0 or NaN', () => {
    const wrapper = mountVenueComparison('en', [JITA_SELL_ROW_WITH_UNKNOWN_FEES, CONTRACT_ROW], null)

    const jitaRow = wrapper.findAll('tbody tr')[0]
    expect({
      notice: [expectedMessage('en', UNKNOWN_FEES_KEY), expectedMessage('en', UNKNOWN_FEES_REASON_KEY)].map(message => wrapper.text().includes(message)),
      unknownCells: [3, 4].map(index => unknownValueTitle(cellOf(jitaRow, index))),
      marginCell: cellOf(jitaRow, 5).text(),
      garbage: /NaN|null|undefined|0\.0%/.test(wrapper.text()),
    }).toEqual({
      notice: [true, true],
      unknownCells: [en.common.unknownValue, en.common.unknownValue],
      marginCell: en.industry.inventionUnknown.label,
      garbage: false,
    })
  })

  it('shows the unknown fees state in French', () => {
    const wrapper = mountVenueComparison('fr', [JITA_SELL_ROW_WITH_UNKNOWN_FEES, CONTRACT_ROW], null)

    expect([expectedMessage('fr', UNKNOWN_FEES_KEY), expectedMessage('fr', UNKNOWN_FEES_REASON_KEY)].map(message => wrapper.text().includes(message))).toEqual([true, true])
    expect(expectedMessage('fr', UNKNOWN_FEES_REASON_KEY)).not.toBe(expectedMessage('en', UNKNOWN_FEES_REASON_KEY))
  })

  it('keeps the broker fee rate and the computed amounts, with no unknown state, when the rates are numbers', () => {
    const wrapper = mountVenueComparison('en', [JITA_SELL_ROW_WITH_KNOWN_FEES, CONTRACT_ROW], 0.03)

    expect({
      rate: wrapper.text().includes(`${en.industry.margins.brokerFeeLabel} 3.0%`),
      fees: cellOf(wrapper.findAll('tbody tr')[0], 3).text(),
      unknownShown: wrapper.text().includes(expectedMessage('en', UNKNOWN_FEES_KEY)),
    }).toEqual({ rate: true, fees: '-137.70 K', unknownShown: false })
  })
})
