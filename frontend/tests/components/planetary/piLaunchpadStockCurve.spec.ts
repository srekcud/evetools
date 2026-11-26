import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PiLaunchpadStockCurve from '@/components/planetary/PiLaunchpadStockCurve.vue'
import type { PlanetaryInputNeedsLaunchpad } from '@/stores/planetary'

const CURVE = '[data-testid="launchpad-curve"]'
const LINE = '[data-testid="launchpad-curve-line"]'
const CAPACITY = '[data-testid="launchpad-curve-capacity"]'
const LAST_VIEW = '[data-testid="launchpad-curve-last-view"]'
const NOW_MARKER = '[data-testid="launchpad-curve-now"]'
const EMPTY_POINT = '[data-testid="launchpad-curve-empty-point"]'
const FLAT = '[data-testid="launchpad-curve-flat"]'
const AGE = '[data-testid="launchpad-curve-age"]'
const UNKNOWN = '[data-testid="launchpad-curve-unknown"]'

const NOW = new Date('2026-11-26T09:00:00+00:00')
const EMPTY_AT_TIMESTAMP = Date.parse('2026-11-28T13:00:00+00:00')
const TWO_DAYS_IN_SECONDS = 172_800

const LAUNCHPAD: PlanetaryInputNeedsLaunchpad = {
  colonyId: 'colony-barren-elite',
  pinId: 1_038_000_000_101,
  capacityM3: 10_000,
  usedVolumeM3: 4_000,
  dailyVolumeM3: 960,
  lastViewAt: '2026-11-24T09:00:00+00:00',
  dataAgeSeconds: TWO_DAYS_IN_SECONDS,
  dataStale: false,
  feeds: [{ typeId: 2_393, typeName: 'Bacteria', emptyAt: '2026-11-28T13:00:00+00:00' }],
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
})

function mountCurve(overrides: Partial<PlanetaryInputNeedsLaunchpad> = {}) {
  return mount(PiLaunchpadStockCurve, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })] },
    props: { launchpad: { ...LAUNCHPAD, ...overrides } },
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

describe('PiLaunchpadStockCurve', () => {
  it('puts the empty point 4.17 days after the last view, with the now marker at the matching share of the way', () => {
    const wrapper = mountCurve()

    existing(wrapper, CURVE)
    expect(existing(wrapper, 'svg').attributes('aria-label')).toBeTruthy()
    const lastViewX = Number(existing(wrapper, LAST_VIEW).attributes('x1'))
    const nowX = Number(existing(wrapper, NOW_MARKER).attributes('x1'))
    const emptyPoint = existing(wrapper, EMPTY_POINT)
    const emptyX = Number(emptyPoint.attributes('cx'))
    expect(Date.parse(emptyPoint.attributes('data-empty-at') ?? '')).toBe(EMPTY_AT_TIMESTAMP)
    expect((nowX - lastViewX) / (emptyX - lastViewX)).toBeCloseTo(2 / (4_000 / 960), 2)
    const [start, end] = pointsOf(wrapper, LINE)
    expect(start.x).toBeCloseTo(lastViewX, 3)
    expect(end.x).toBeCloseTo(emptyX, 3)
    expect(end.y).toBeCloseTo(Number(emptyPoint.attributes('cy')), 3)
    expect(end.y).toBeGreaterThan(start.y)
    expect(Number(existing(wrapper, CAPACITY).attributes('y1'))).toBeLessThan(start.y)
  })

  it('draws a flat line without empty point and says the stock is not consumed when the daily volume is zero', () => {
    const wrapper = mountCurve({ dailyVolumeM3: 0 })

    existing(wrapper, FLAT)
    const [start, end] = pointsOf(wrapper, LINE)
    expect(end.y).toBeCloseTo(start.y, 5)
    expect(wrapper.find(EMPTY_POINT).exists()).toBe(false)
    existing(wrapper, CAPACITY)
  })

  it('shows the data age in orange when the colony data is stale and not otherwise', () => {
    const stale = mountCurve({ dataStale: true, dataAgeSeconds: 9 * 86_400 })
    const fresh = mountCurve()

    expect(existing(stale, AGE).classes().join(' ')).toMatch(/amber|orange/)
    expect(existing(fresh, AGE).classes().join(' ')).not.toMatch(/amber|orange/)
  })

  it.each([
    ['capacity', { capacityM3: null }],
    ['used volume', { usedVolumeM3: null }],
  ])('says the stock is unknown and draws no line, capacity or empty point when the %s is unknown', (_label, overrides) => {
    const wrapper = mountCurve(overrides)

    existing(wrapper, CURVE)
    existing(wrapper, UNKNOWN)
    expect(wrapper.find(LINE).exists()).toBe(false)
    expect(wrapper.find(CAPACITY).exists()).toBe(false)
    expect(wrapper.find(EMPTY_POINT).exists()).toBe(false)
  })
})
