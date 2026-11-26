import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiExtractorYieldCurve from '@/components/planetary/PiExtractorYieldCurve.vue'
import type { ExtractorYieldCurve } from '@/stores/planetary'

const CURVE = '[data-testid="yield-curve"]'
const LINE = '[data-testid="yield-curve-line"]'
const CONSTANT = '[data-testid="yield-curve-constant"]'
const NOW_MARKER = '[data-testid="yield-curve-now"]'
const SUMMARY = '[data-testid="yield-curve-summary"]'
const SUGGESTION = '[data-testid="yield-curve-suggestion"]'
const UNKNOWN = '[data-testid="yield-curve-unknown"]'

const INSTALL_TIME = '2026-11-26T08:00:00+00:00'
const EXPIRY_TIME = '2026-11-26T10:00:00+00:00'
const NOW_HALFWAY = new Date('2026-11-26T09:00:00+00:00')
const NOW_AFTER_EXPIRY = new Date('2026-11-26T12:00:00+00:00')

const CURVE_OF_FOUR_CYCLES: ExtractorYieldCurve = {
  cycleSeconds: 1_800,
  yields: [8_000, 6_000, 4_000, 2_000],
  totalQuantity: 20_000,
  averagePerHour: 10_000,
  suggestedDays: 11,
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW_HALFWAY)
})

afterEach(() => {
  vi.useRealTimers()
})

function mountCurve(curve: ExtractorYieldCurve | null, installTime: string | null = INSTALL_TIME, expiryTime: string | null = EXPIRY_TIME) {
  return mount(PiExtractorYieldCurve, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { curve, installTime, expiryTime },
  })
}

function existing(wrapper: VueWrapper, selector: string) {
  const found = wrapper.find(selector)
  expect(found.exists()).toBe(true)
  return found
}

function pointsOf(wrapper: VueWrapper, selector: string): { x: number; y: number }[] {
  const raw = existing(wrapper, selector).attributes('points') ?? ''
  return raw.trim().split(/\s+/).map(pair => {
    const [x, y] = pair.split(',').map(Number)
    return { x, y }
  })
}

function figures(text: string): string {
  return text.replace(/[\s,\u00a0\u202f]/g, '')
}

describe('PiExtractorYieldCurve', () => {
  it('draws the hourly rate of each cycle as one descending point per cycle and a dashed straight line at the initial rate', () => {
    const wrapper = mountCurve(CURVE_OF_FOUR_CYCLES)

    existing(wrapper, CURVE)
    expect(existing(wrapper, 'svg').attributes('aria-label')).toBeTruthy()
    const points = pointsOf(wrapper, LINE)
    expect(points).toHaveLength(4)
    points.slice(1).forEach((point, index) => {
      expect(point.x).toBeGreaterThan(points[index].x)
      expect(point.y).toBeGreaterThan(points[index].y)
    })
    const constant = existing(wrapper, CONSTANT)
    expect(constant.attributes('stroke-dasharray')).toBeTruthy()
    expect(Number(constant.attributes('y1'))).toBe(Number(constant.attributes('y2')))
    expect(Number(constant.attributes('y1'))).toBeCloseTo(points[0].y, 5)
  })

  it('places the now marker halfway between installation and expiry and draws none once the program is over', () => {
    const wrapper = mountCurve(CURVE_OF_FOUR_CYCLES)

    const constant = existing(wrapper, CONSTANT)
    const programStart = Number(constant.attributes('x1'))
    const programEnd = Number(constant.attributes('x2'))
    expect(programEnd).toBeGreaterThan(programStart)
    expect(Number(existing(wrapper, NOW_MARKER).attributes('x1'))).toBeCloseTo((programStart + programEnd) / 2, 3)

    vi.setSystemTime(NOW_AFTER_EXPIRY)
    expect(mountCurve(CURVE_OF_FOUR_CYCLES).find(NOW_MARKER).exists()).toBe(false)
  })

  it('states the total quantity, the average per hour and the gap of the constant rate to that average', () => {
    const wrapper = mountCurve(CURVE_OF_FOUR_CYCLES)

    const summary = figures(existing(wrapper, SUMMARY).text())
    expect(summary).toContain('20000')
    expect(summary).toContain('10000')
    expect(summary).toContain('+60%')
  })

  it('suggests restarting on the number of days of the program when it is known and says nothing when it is not', () => {
    const known = mountCurve(CURVE_OF_FOUR_CYCLES)
    const unknown = mountCurve({ ...CURVE_OF_FOUR_CYCLES, suggestedDays: null })

    expect(figures(existing(known, SUGGESTION).text())).toContain('11')
    expect(unknown.find(SUGGESTION).exists()).toBe(false)
    existing(unknown, LINE)
  })

  it('says the curve is unknown and draws no line, no straight rate and no figure when the program has no curve', () => {
    const wrapper = mountCurve(null, null, EXPIRY_TIME)

    existing(wrapper, CURVE)
    existing(wrapper, UNKNOWN)
    expect(wrapper.find(LINE).exists()).toBe(false)
    expect(wrapper.find(CONSTANT).exists()).toBe(false)
    expect(wrapper.find(SUMMARY).exists()).toBe(false)
    expect(wrapper.find(SUGGESTION).exists()).toBe(false)
  })
})
