import { enableAutoUnmount, mount, RouterLinkStub, type DOMWrapper } from '@vue/test-utils'
import MiningStockpileCoverage from '@/components/mining-operation/MiningStockpileCoverage.vue'
import type { StockpileCoverage } from '@/composables/useStockpileCoverage'
import { messageOf } from '../../localeTexts'
import { i18nPlugin } from '../ledger/miningLedgerHarness'
import {
  COVERAGE_WITHOUT_YIELD,
  ISOGEN_TYPE_ID,
  MEXALLON_TYPE_ID,
  PYERITE_TYPE_ID,
  TRITANIUM_PARTLY_COVERED,
  TRITANIUM_TYPE_ID,
  stockpileCoverage,
} from './stockpileCoverageHarness'

const LONG_MINERAL_NAME = 'Exceptionally Refined Hypothetical Tritanium Isotope With An Overly Long Name'

function mountCoverage(coverage: StockpileCoverage) {
  return mount(MiningStockpileCoverage, {
    props: { coverage },
    global: { plugins: [i18nPlugin()], stubs: { RouterLink: RouterLinkStub } },
  })
}

type CoverageWrapper = ReturnType<typeof mountCoverage>

function mineralRow(wrapper: CoverageWrapper, typeId: number) {
  const row = wrapper.find(`[data-coverage-mineral="${typeId}"]`)
  expect(row.exists(), `row of mineral ${typeId}`).toBe(true)
  return row
}

function found(parent: { find(selector: string): DOMWrapper<Element> }, selector: string) {
  const element = parent.find(selector)
  expect(element.exists(), selector).toBe(true)
  return element
}

enableAutoUnmount(afterEach)

describe('MiningStockpileCoverage', () => {
  it('lists one row per produced mineral in the order received, each with its coverage state', () => {
    const wrapper = mountCoverage(stockpileCoverage())

    const rows = wrapper.findAll('[data-coverage-mineral]')
    expect(rows.map(row => [row.attributes('data-coverage-mineral'), row.attributes('data-coverage-state')])).toEqual([
      [String(TRITANIUM_TYPE_ID), 'partial'],
      [String(PYERITE_TYPE_ID), 'whole'],
      [String(MEXALLON_TYPE_ID), 'no-deficit'],
      [String(ISOGEN_TYPE_ID), 'unknown'],
    ])
    expect(found(wrapper, '[data-stockpile-coverage]').text()).toContain(messageOf('en', 'miningOperation.stockpileCoverage.title'))
  })

  it('shows the share of the deficit covered by the operation for each mineral with a deficit', () => {
    const wrapper = mountCoverage(stockpileCoverage())

    const tritanium = mineralRow(wrapper, TRITANIUM_TYPE_ID)
    expect(found(tritanium, '[data-coverage-percent]').text()).toMatch(/^56\.65\s?%$/)
    expect(found(tritanium, '[data-coverage-mineral-name]').text()).toBe('Tritanium')
    expect(found(mineralRow(wrapper, PYERITE_TYPE_ID), '[data-coverage-percent]').text()).toMatch(/^100\s?%$/)
    expect(found(mineralRow(wrapper, PYERITE_TYPE_ID), '[data-produced-quantity]').text()).toMatch(/^3[\s,.\u00a0\u202f]?600$/)
  })

  it('says a mineral without deficit has none in the Stockpile and shows no percentage', () => {
    const wrapper = mountCoverage(stockpileCoverage())

    const mexallon = mineralRow(wrapper, MEXALLON_TYPE_ID)
    expect(mexallon.text()).toContain(messageOf('en', 'miningOperation.stockpileCoverage.noDeficit'))
    expect(mexallon.find('[data-coverage-percent]').exists()).toBe(false)
  })

  it('shows an unknown coverage and an unknown produced quantity as — and never as 0', () => {
    const wrapper = mountCoverage(stockpileCoverage())

    const isogen = mineralRow(wrapper, ISOGEN_TYPE_ID)
    expect([found(isogen, '[data-coverage-percent]').text(), found(isogen, '[data-produced-quantity]').text()]).toEqual(['—', '—'])
    expect(isogen.text()).not.toMatch(/\b0\s?%/)
  })

  it('asks to configure the reprocessing yield with a link to its panel instead of listing minerals when the yield is unknown', () => {
    const wrapper = mountCoverage(COVERAGE_WITHOUT_YIELD)

    const yieldUnknown = found(wrapper, '[data-coverage-yield-unknown]')
    expect(yieldUnknown.text()).toContain(messageOf('en', 'miningOperation.stockpileCoverage.yieldUnknown'))
    expect(yieldUnknown.findComponent(RouterLinkStub).props('to')).toEqual({ name: 'mining-ore-value' })
    expect(wrapper.findAll('[data-coverage-mineral]')).toHaveLength(0)
  })

  it.each([
    ['measuring', null, '[data-coverage-measuring]', 'miningOperation.stockpileCoverage.measuring'],
    ['unknown', 'reconnect_required', '[data-coverage-unknown]', 'miningOperation.stockpileCoverage.unknown'],
  ] as const)('says the quantities are %s instead of any coverage', (state, unknownReason, selector, key) => {
    const wrapper = mountCoverage(stockpileCoverage({ state, unknownReason, minerals: [] }))

    expect(found(wrapper, selector).text()).toContain(messageOf('en', key))
    expect(wrapper.findAll('[data-coverage-mineral]')).toHaveLength(0)
    expect(wrapper.find('[data-coverage-yield-unknown]').exists()).toBe(false)
  })

  it('keeps a long mineral name whole in its title', () => {
    const wrapper = mountCoverage(stockpileCoverage({ minerals: [{ ...TRITANIUM_PARTLY_COVERED, typeName: LONG_MINERAL_NAME }] }))

    expect(found(mineralRow(wrapper, TRITANIUM_TYPE_ID), '[data-coverage-mineral-name]').attributes('title')).toBe(LONG_MINERAL_NAME)
  })
})
