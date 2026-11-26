import { enableAutoUnmount, mount, type DOMWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiExtractionBalance from '@/components/planetary/PiExtractionBalance.vue'
import type { ExtractionBalanceRow } from '@/stores/planetary'

const BLOCK = '[data-testid="extraction-balance"]'
const ROW = '[data-testid="extraction-balance-row"]'
const EXTRACTED = '[data-testid="extraction-balance-extracted"]'
const DEMANDED = '[data-testid="extraction-balance-demanded"]'
const BALANCE = '[data-testid="extraction-balance-balance"]'
const FACTORIES = '[data-testid="extraction-balance-factories"]'
const HEADS = '[data-testid="extraction-balance-heads"]'
const GAUGE = '[data-testid="extraction-balance-gauge"]'
const ADVICE = '[data-testid="extraction-balance-advice"]'
const ADVICE_HEADS = '[data-testid="extraction-balance-advice-heads"]'
const ADVICE_FACTORIES = '[data-testid="extraction-balance-advice-factories"]'
const UNKNOWN = '[data-testid="extraction-balance-unknown"]'

const MINUS = '−'
const LONG_TYPE_NAME = 'Planktic Colonies of the Verhoeven Deep Core Reach'

const BACTERIA_DEFICIT: ExtractionBalanceRow = {
  typeId: 2_393,
  typeName: 'Bacteria',
  extractedPerDay: 100_000,
  demandedPerDay: 288_000,
  balancePerDay: -188_000,
  runRate: 0.35,
  factoryCount: 2,
  headCount: 2,
  advice: { kind: 'deficit', heads: 4, factories: 2 },
}

const LONG_NAME_BALANCED: ExtractionBalanceRow = {
  typeId: 2_073,
  typeName: LONG_TYPE_NAME,
  extractedPerDay: 432_000,
  demandedPerDay: 432_000,
  balancePerDay: 0,
  runRate: 1,
  factoryCount: 3,
  headCount: 7,
  advice: { kind: 'balanced', heads: null, factories: null },
}

const PROTEINS_SURPLUS: ExtractionBalanceRow = {
  typeId: 2_395,
  typeName: 'Proteins',
  extractedPerDay: 576_000,
  demandedPerDay: 288_000,
  balancePerDay: 288_000,
  runRate: 1,
  factoryCount: 2,
  headCount: 6,
  advice: { kind: 'surplus', heads: null, factories: 2 },
}

const BASE_METALS_UNKNOWN: ExtractionBalanceRow = {
  typeId: 2_267,
  typeName: 'Base Metals',
  extractedPerDay: null,
  demandedPerDay: 144_000,
  balancePerDay: null,
  runRate: null,
  factoryCount: 1,
  headCount: 2,
  advice: null,
}

const REACTIVE_METALS_UNKNOWN_HEADS: ExtractionBalanceRow = {
  typeId: 2_398,
  typeName: 'Reactive Metals',
  extractedPerDay: 144_000,
  demandedPerDay: 288_000,
  balancePerDay: -144_000,
  runRate: 0.5,
  factoryCount: 2,
  headCount: null,
  advice: { kind: 'deficit', heads: null, factories: 1 },
}

enableAutoUnmount(afterEach)

function mountBalance(balance: ExtractionBalanceRow[]) {
  return mount(PiExtractionBalance, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { balance },
  })
}

function existing(wrapper: { find(selector: string): DOMWrapper<Element> }, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists()).toBe(true)
  return found
}

function figures(text: string): string {
  return text.replace(/[\s,\u00a0\u202f]/g, '')
}

describe('PiExtractionBalance', () => {
  it('shows one row per extracted and demanded type with its name, extraction, demand, balance, factories and heads', () => {
    const wrapper = mountBalance([BACTERIA_DEFICIT, LONG_NAME_BALANCED])

    existing(wrapper, BLOCK)
    const rows = wrapper.findAll(ROW)
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Bacteria')
    expect(figures(existing(rows[0], EXTRACTED).text())).toContain('100000')
    expect(figures(existing(rows[0], DEMANDED).text())).toContain('288000')
    expect(figures(existing(rows[0], BALANCE).text())).toContain('188000')
    expect(rows[1].text()).toContain(LONG_TYPE_NAME)
    expect(figures(existing(rows[1], EXTRACTED).text())).toContain('432000')
    expect(figures(existing(rows[1], FACTORIES).text())).toContain('3')
    expect(figures(existing(rows[1], HEADS).text())).toContain('7')
  })

  it('draws the run rate gauge green at 100 percent, amber from 50 to under 100 percent and red under 50 percent', () => {
    const full = { ...LONG_NAME_BALANCED, typeId: 2_073, runRate: 1 }
    const threeQuarters = { ...PROTEINS_SURPLUS, typeId: 2_395, runRate: 0.75 }
    const half = { ...REACTIVE_METALS_UNKNOWN_HEADS, typeId: 2_398, runRate: 0.5 }
    const wrapper = mountBalance([full, threeQuarters, half, BACTERIA_DEFICIT])

    const gauges = wrapper.findAll(GAUGE)
    expect(gauges).toHaveLength(4)
    expect(gauges.map(gauge => gauge.attributes('data-state'))).toEqual(['normal', 'warning', 'warning', 'critical'])
    expect(figures(gauges[0].text())).toContain('100%')
    expect(figures(gauges[1].text())).toContain('75%')
    expect(figures(gauges[3].text())).toContain('35%')
  })

  it('advises the heads to add or the factories to remove when the extraction falls short of the demand', () => {
    const wrapper = mountBalance([BACTERIA_DEFICIT])

    const advice = existing(wrapper, ADVICE)
    expect(advice.attributes('data-kind')).toBe('deficit')
    expect(figures(existing(wrapper, ADVICE_HEADS).text())).toContain('+4')
    expect(figures(existing(wrapper, ADVICE_FACTORIES).text())).toContain(`${MINUS}2`)
  })

  it('advises the factories which still hold when the extraction exceeds the demand by at least one factory', () => {
    const wrapper = mountBalance([PROTEINS_SURPLUS])

    expect(existing(wrapper, ADVICE).attributes('data-kind')).toBe('surplus')
    expect(figures(existing(wrapper, ADVICE_FACTORIES).text())).toContain('2')
    expect(wrapper.find(ADVICE_HEADS).exists()).toBe(false)
  })

  it('states the balance when the extraction matches the demand and advises neither heads nor factories', () => {
    const wrapper = mountBalance([LONG_NAME_BALANCED])

    expect(existing(wrapper, ADVICE).attributes('data-kind')).toBe('balanced')
    expect(wrapper.find(ADVICE_HEADS).exists()).toBe(false)
    expect(wrapper.find(ADVICE_FACTORIES).exists()).toBe(false)
  })

  it('says the row is unknown, keeps the demand and draws no gauge and no advice when the extraction is unknown', () => {
    const wrapper = mountBalance([BASE_METALS_UNKNOWN])

    const row = existing(wrapper, ROW)
    existing(wrapper, UNKNOWN)
    expect(figures(existing(row, DEMANDED).text())).toContain('144000')
    expect(wrapper.find(GAUGE).exists()).toBe(false)
    expect(wrapper.find(ADVICE).exists()).toBe(false)
    expect(wrapper.find(ADVICE_HEADS).exists()).toBe(false)
    expect(wrapper.find(ADVICE_FACTORIES).exists()).toBe(false)
  })

  it('says only the factory option when the number of heads is unknown', () => {
    const wrapper = mountBalance([REACTIVE_METALS_UNKNOWN_HEADS])

    expect(existing(wrapper, ADVICE).attributes('data-kind')).toBe('deficit')
    expect(figures(existing(wrapper, ADVICE_FACTORIES).text())).toContain(`${MINUS}1`)
    expect(wrapper.find(ADVICE_HEADS).exists()).toBe(false)
    expect(wrapper.find(UNKNOWN).exists()).toBe(false)
  })

  it('renders no block at all when the colony has no balance', () => {
    const wrapper = mountBalance([])

    expect(wrapper.find(BLOCK).exists()).toBe(false)
    expect(wrapper.find(ROW).exists()).toBe(false)
  })
})
