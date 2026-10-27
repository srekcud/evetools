import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import VenueComparisonTable, { type SellRow } from '@/components/industry/profit/VenueComparisonTable.vue'
import { tablesInScrollContainer } from '../tableScrollContainer'

const JITA_SELL_ROW: SellRow = {
  key: 'jitaSell',
  venue: 'Jita 4-4',
  tag: 'Undercut',
  unitPrice: 61.2,
  revenue: 1_836_000,
  fees: 137_700,
  profit: 131_972,
  margin: 8.43,
  dailyVolume: 125_000,
  contractCount: null,
  isBest: true,
}

const STRUCTURE_BUY_ROW: SellRow = {
  key: 'structureBuy',
  venue: 'Tatara Alpha',
  tag: 'Instant sell',
  unitPrice: 54.1,
  revenue: 1_623_000,
  fees: 73_035,
  profit: -16_362,
  margin: -1.04,
  dailyVolume: null,
  contractCount: null,
  isBest: false,
}

function mountVenueComparison() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(VenueComparisonTable, {
    global: { plugins: [i18n] },
    props: { rows: [JITA_SELL_ROW, STRUCTURE_BUY_ROW], runs: 3, brokerFeeRate: 0.03 },
  })
}

describe('VenueComparisonTable on a narrow screen', () => {
  it('puts the venue table in a horizontally scrollable container', () => {
    const wrapper = mountVenueComparison()

    expect(tablesInScrollContainer(wrapper)).toEqual([true])
  })
})
