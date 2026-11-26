import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import type { Colony, PlanetaryInputNeeds } from '@/stores/planetary'
import { apiRequest } from '@/services/api'

const route = reactive<{ path: string; params: Record<string, string>; query: LocationQuery }>({ path: '/planetary', params: {}, query: {} })

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ replace: vi.fn(), push: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const HOUR_MS = 3_600_000
const NOW = Date.parse('2026-11-26T09:00:00+00:00')
const ILYRA_ID = 2113830001
const DORAN_ID = 2113830002

function at(hoursFromNow: number): string {
  return new Date(NOW + hoursFromNow * HOUR_MS).toISOString()
}

function colony(overrides: Partial<Colony>): Colony {
  return {
    id: 'colony',
    characterId: ILYRA_ID,
    characterName: 'Ilyra Castell',
    planetId: 40009077,
    planetName: 'Jita IV',
    planetType: 'barren',
    solarSystemId: 30000142,
    solarSystemName: 'Jita',
    solarSystemSecurity: 0.9,
    upgradeLevel: 4,
    tier: null,
    storageFull: null,
    commandCenterUpgradable: false,
    numPins: 12,
    lastUpdate: at(-6),
    cachedAt: at(-6),
    extractorCount: 0,
    factoryCount: 0,
    activeExtractors: 0,
    nearestExpiry: null,
    factoryProgress: null,
    status: 'idle',
    pins: [],
    routes: [],
    ...overrides,
  }
}

const COLONIES: Colony[] = [
  colony({ id: 'ilyra-barren', planetId: 40009077, planetName: 'Jita IV', planetType: 'barren', status: 'expiring', tier: 'P1', extractorCount: 2, activeExtractors: 2, nearestExpiry: at(5) }),
  colony({ id: 'ilyra-ice', planetId: 40009078, planetName: 'Jita VII', planetType: 'ice', status: 'factory', tier: 'P2', factoryCount: 6 }),
  colony({ id: 'doran-gas', characterId: DORAN_ID, characterName: 'Doran Vex', planetId: 40009081, planetName: 'Perimeter II', planetType: 'gas', solarSystemId: 30000144, solarSystemName: 'Perimeter', status: 'factory', tier: 'P4', storageFull: true, lastUpdate: at(-3) }),
  colony({ id: 'doran-lava', characterId: DORAN_ID, characterName: 'Doran Vex', planetId: 40009090, planetName: 'Amamake III', planetType: 'lava', solarSystemId: 30002537, solarSystemName: 'Amamake', commandCenterUpgradable: true }),
  colony({ id: 'doran-ice', characterId: DORAN_ID, characterName: 'Doran Vex', planetId: 40009082, planetName: 'Perimeter IV', planetType: 'ice', solarSystemId: 30000144, solarSystemName: 'Perimeter', status: 'expired', tier: 'P1', extractorCount: 1, nearestExpiry: at(-48), commandCenterUpgradable: true }),
]

const PLANET_NAMES = COLONIES.map(item => item.planetName as string)

const INPUT_NEEDS: PlanetaryInputNeeds & Record<string, unknown> = {
  colonies: [],
  extractions: [],
  weekly: [],
  complete: true,
  launchpads: [
    { colonyId: 'ilyra-ice', pinId: 1021, feeds: [{ typeId: 2393, typeName: 'Bacteria', emptyAt: at(-1) }] },
  ],
}

async function mountView(inputNeedsAvailable = true) {
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return Promise.resolve(COLONIES)
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
    if (endpoint === '/planetary/slots') return Promise.resolve({ characters: [], totalUsed: 0, totalMax: 0 })
    if (endpoint === '/planetary/input-needs') {
      return inputNeedsAvailable ? Promise.resolve(INPUT_NEEDS) : Promise.reject(new Error('input needs unavailable'))
    }
    return new Promise(() => {})
  })
  setActivePinia(createPinia())
  const wrapper = mount(PlanetaryInteraction, {
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        ErrorBanner: true,
        PiAlertsBanner: true,
        PiProductionTable: true,
        PiTierLegend: true,
        PiKpiCards: true,
        PiPlannerTab: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>
type Card = ReturnType<Wrapper['findAll']>[number]

function planetNameOf(card: Card): string {
  const name = PLANET_NAMES.find(candidate => card.text().includes(candidate))
  expect(name).toBeDefined()
  return name as string
}

function cards(wrapper: Wrapper): Card[] {
  return wrapper.findAll('[data-testid="colony-card"]')
}

function cardOf(wrapper: Wrapper, planetName: string): Card {
  const found = cards(wrapper).find(card => planetNameOf(card) === planetName)
  expect(found).toBeDefined()
  return found as Card
}

function planetNames(wrapper: Wrapper): string[] {
  return cards(wrapper).map(planetNameOf)
}

function chip(wrapper: Wrapper, id: string) {
  const found = wrapper.find(`[data-testid="filter-chip-${id}"]`)
  expect(found.exists()).toBe(true)
  return found
}

async function sortBy(wrapper: Wrapper, mode: string) {
  await wrapper.get('[data-testid="sort-select"]').setValue(mode)
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('PlanetaryInteraction colonies sorted by next action', () => {
  it('sorts by next action by default and by character or system when asked, the character groups following their first deadline', async () => {
    const wrapper = await mountView()

    const select = wrapper.get('[data-testid="sort-select"]')
    expect((select.element as HTMLSelectElement).value).toBe('nextAction')
    expect(planetNames(wrapper)).toEqual(['Perimeter IV', 'Perimeter II', 'Jita VII', 'Jita IV', 'Amamake III'])

    await sortBy(wrapper, 'character')
    expect(planetNames(wrapper)).toEqual(['Jita IV', 'Jita VII', 'Perimeter II', 'Amamake III', 'Perimeter IV'])

    await sortBy(wrapper, 'system')
    expect(planetNames(wrapper)).toEqual(['Amamake III', 'Jita IV', 'Jita VII', 'Perimeter II', 'Perimeter IV'])

    await sortBy(wrapper, 'nextAction')
    await wrapper.get('[data-testid="group-by-character"]').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[data-testid="colony-group-title"]').map(title => title.text())).toEqual(['Doran Vex', 'Ilyra Castell'])
  })

  it('shows on each row the tier badge and the next action, red when already past, and nothing for a colony without tier or action', async () => {
    const wrapper = await mountView()

    const storage = cardOf(wrapper, 'Perimeter II')
    expect(storage.get('[data-testid="tier-badge"]').text()).toBe('P4')
    expect(storage.get('[data-testid="next-action-label"]').attributes('data-action-type')).toBe('storage_full')
    expect(storage.get('[data-testid="next-action-label"]').attributes('data-overdue')).toBe('true')
    const refill = cardOf(wrapper, 'Jita VII')
    expect(refill.get('[data-testid="tier-badge"]').text()).toBe('P2')
    expect(refill.get('[data-testid="next-action-label"]').attributes('data-action-type')).toBe('refill')
    const restart = cardOf(wrapper, 'Jita IV')
    expect(restart.get('[data-testid="next-action-label"]').attributes('data-action-type')).toBe('restart')
    expect(restart.get('[data-testid="next-action-label"]').attributes('data-overdue')).toBe('false')
    const calm = cardOf(wrapper, 'Amamake III')
    expect(calm.find('[data-testid="tier-badge"]').exists()).toBe(false)
    expect(calm.find('[data-testid="next-action-label"]').exists()).toBe(false)
    const section = wrapper.get('[data-testid="colony-group-section"]')
    expect(section.get('[data-testid="group-next-action"]').attributes('data-action-type')).toBe('restart')
  })
})

describe('PlanetaryInteraction colonies quick filters', () => {
  it('shows every chip with its count, zero included, and keeps only the matching colonies when a chip is clicked', async () => {
    const wrapper = await mountView()

    const counts = Object.fromEntries(['all', 'P1', 'P2', 'P3', 'P4', 'restart', 'refill', 'storage_full', 'upgrade'].map(id => [id, chip(wrapper, id).attributes('data-count')]))
    expect(counts).toEqual({ all: '5', P1: '2', P2: '1', P3: '0', P4: '1', restart: '2', refill: '1', storage_full: '1', upgrade: '2' })
    expect(chip(wrapper, 'all').attributes('aria-pressed')).toBe('true')

    await chip(wrapper, 'P1').trigger('click')
    expect(chip(wrapper, 'P1').attributes('aria-pressed')).toBe('true')
    expect(chip(wrapper, 'all').attributes('aria-pressed')).toBe('false')
    expect(planetNames(wrapper)).toEqual(['Perimeter IV', 'Jita IV'])

    await chip(wrapper, 'upgrade').trigger('click')
    expect(planetNames(wrapper)).toEqual(['Perimeter IV', 'Amamake III'])

    await chip(wrapper, 'refill').trigger('click')
    expect(planetNames(wrapper)).toEqual(['Jita VII'])
  })

  it('says no colony matches a filter that leaves none and goes back to all colonies with the reset button', async () => {
    const wrapper = await mountView()

    await chip(wrapper, 'P3').trigger('click')

    expect(cards(wrapper)).toHaveLength(0)
    expect(wrapper.find('[data-testid="filter-empty"]').exists()).toBe(true)
    await wrapper.get('[data-testid="filter-reset"]').trigger('click')
    expect(wrapper.find('[data-testid="filter-empty"]').exists()).toBe(false)
    expect(cards(wrapper)).toHaveLength(5)
    expect(chip(wrapper, 'all').attributes('aria-pressed')).toBe('true')
  })

  it('shows the refill chip as a question mark with a tooltip and invents no refill action when the input needs fail to load', async () => {
    const wrapper = await mountView(false)

    expect(chip(wrapper, 'refill').attributes('data-count')).toBe('?')
    expect(chip(wrapper, 'refill').text()).toContain('?')
    expect(chip(wrapper, 'refill').attributes('title')).toBeTruthy()
    expect(chip(wrapper, 'restart').attributes('data-count')).toBe('2')
    expect(planetNames(wrapper)).toEqual(['Perimeter IV', 'Perimeter II', 'Jita IV', 'Jita VII', 'Amamake III'])
    expect(cardOf(wrapper, 'Jita VII').find('[data-testid="next-action-label"]').exists()).toBe(false)
  })
})
