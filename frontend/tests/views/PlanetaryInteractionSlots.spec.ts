import { reactive } from 'vue'
import type { LocationQuery } from 'vue-router'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PlanetaryInteraction from '@/views/PlanetaryInteraction.vue'
import type { Colony, PlanetarySlots } from '@/stores/planetary'
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

const ILYRA_ID = 2113840001
const DORAN_ID = 2113840002
const SERA_ID = 2113840003

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
    numPins: 12,
    lastUpdate: '2026-10-22T09:00:00+00:00',
    cachedAt: '2026-10-22T09:05:00+00:00',
    extractorCount: 2,
    factoryCount: 6,
    activeExtractors: 2,
    nearestExpiry: '2026-10-24T09:00:00+00:00',
    factoryProgress: null,
    status: 'active',
    pins: [],
    routes: [],
    ...overrides,
  }
}

const COLONIES: Colony[] = [
  colony({ id: 'ilyra-barren', characterId: ILYRA_ID, characterName: 'Ilyra Castell', planetId: 40009077, planetName: 'Jita IV', planetType: 'barren' }),
  colony({ id: 'ilyra-ice', characterId: ILYRA_ID, characterName: 'Ilyra Castell', planetId: 40009078, planetName: 'Jita V', planetType: 'ice' }),
  colony({ id: 'doran-gas', characterId: DORAN_ID, characterName: 'Doran Vex', planetId: 40009081, planetName: 'Perimeter II', planetType: 'gas' }),
]

const KNOWN_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: ILYRA_ID, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 2, consolidationLevel: 4, maxColonies: 5 },
    { characterId: DORAN_ID, characterName: 'Doran Vex', isMain: false, coloniesUsed: 1, consolidationLevel: 2, maxColonies: 3 },
    { characterId: SERA_ID, characterName: 'Sera Idle', isMain: false, coloniesUsed: 0, consolidationLevel: 0, maxColonies: 1 },
  ],
  totalUsed: 3,
  totalMax: 9,
}

const UNKNOWN_MAXIMUM_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: ILYRA_ID, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 2, consolidationLevel: 4, maxColonies: 5 },
    { characterId: DORAN_ID, characterName: 'Doran Vex', isMain: false, coloniesUsed: 1, consolidationLevel: null, maxColonies: null },
    { characterId: SERA_ID, characterName: 'Sera Idle', isMain: false, coloniesUsed: 0, consolidationLevel: 0, maxColonies: 1 },
  ],
  totalUsed: 3,
  totalMax: null,
}

const OVER_MAXIMUM_SLOTS: PlanetarySlots = {
  characters: [
    { characterId: ILYRA_ID, characterName: 'Ilyra Castell', isMain: true, coloniesUsed: 2, consolidationLevel: 0, maxColonies: 1 },
    { characterId: DORAN_ID, characterName: 'Doran Vex', isMain: false, coloniesUsed: 1, consolidationLevel: 0, maxColonies: 1 },
  ],
  totalUsed: 3,
  totalMax: 2,
}

const RED_CLASS = 'text-red-400'
const SKILL_NAME = 'Interplanetary Consolidation'

async function mountView(slots: PlanetarySlots) {
  route.query = {}
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((endpoint: string) => {
    if (endpoint === '/planetary') return Promise.resolve(COLONIES)
    if (endpoint === '/planetary/stats') return Promise.resolve({})
    if (endpoint === '/planetary/production') return Promise.resolve({ tiers: [] })
    if (endpoint === '/planetary/slots') return Promise.resolve(slots)
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
type Section = ReturnType<Wrapper['findAll']>[number]

function sections(wrapper: Wrapper): Section[] {
  return wrapper.findAll('[data-testid="colony-group-section"]')
}

function accountSection(wrapper: Wrapper): Section {
  const found = sections(wrapper)
  expect(found).toHaveLength(1)
  return found[0]
}

function characterSections(wrapper: Wrapper): Section[] {
  const found = sections(wrapper)
  expect(found).toHaveLength(2)
  return found
}

function badgeOf(section: Section) {
  const badge = section.find('[data-testid="slots-badge"]')
  expect(badge.exists()).toBe(true)
  return badge
}

function groupByButton(wrapper: Wrapper, mode: 'account' | 'character') {
  const button = wrapper.find(`[data-testid="group-by-${mode}"]`)
  expect(button.exists()).toBe(true)
  return button
}

async function groupBy(wrapper: Wrapper, mode: 'account' | 'character') {
  await groupByButton(wrapper, mode).trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

describe('PlanetaryInteraction colonies grouped by account', () => {
  it('groups the colonies under one account section named after the main character, grouping by account by default', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    const section = accountSection(wrapper)
    expect(groupByButton(wrapper, 'account').attributes('aria-pressed')).toBe('true')
    expect(groupByButton(wrapper, 'character').attributes('aria-pressed')).toBe('false')
    expect(section.get('[data-testid="colony-group-title"]').text()).toContain('Ilyra Castell')
    expect(section.findAll('[data-testid="colony-card"]')).toHaveLength(3)
  })

  it('shows the colonies used over the colony slots of the whole account in the section badge', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    const badge = badgeOf(accountSection(wrapper))
    expect(badge.text()).toMatch(/3\s*\/\s*9/)
    expect(badge.classes()).not.toContain(RED_CLASS)
  })

  it('lists every character of the account with its colonies used over its maximum, one without colony included', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    const rows = accountSection(wrapper).findAll('[data-testid="slots-character"]').map(row => row.text())
    expect(rows).toHaveLength(3)
    expect(rows[0]).toMatch(/Ilyra Castell\s*2\s*\/\s*5/)
    expect(rows[1]).toMatch(/Doran Vex\s*1\s*\/\s*3/)
    expect(rows[2]).toMatch(/Sera Idle\s*0\s*\/\s*1/)
  })

  it('shows an avatar only for the characters that have colonies', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    const avatars = accountSection(wrapper).findAll('[data-testid="account-avatar"]')
    expect(avatars.map(avatar => avatar.attributes('alt'))).toEqual(['Ilyra Castell', 'Doran Vex'])
    expect(avatars.map(avatar => avatar.attributes('src'))).toEqual([
      `https://images.evetech.net/characters/${ILYRA_ID}/portrait?size=64`,
      `https://images.evetech.net/characters/${DORAN_ID}/portrait?size=64`,
    ])
  })

  it('names the character on every colony card of the account section', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    expect(accountSection(wrapper).findAll('[data-testid="colony-character-name"]').map(name => name.text())).toEqual([
      'Ilyra Castell',
      'Ilyra Castell',
      'Doran Vex',
    ])
  })

  it('shows an unknown total maximum as a question mark with the unsynchronized skill in the tooltip, never a presumed maximum', async () => {
    const wrapper = await mountView(UNKNOWN_MAXIMUM_SLOTS)

    const section = accountSection(wrapper)
    const badge = badgeOf(section)
    expect(badge.text()).toMatch(/3\s*\/\s*\?/)
    expect(badge.attributes('title')).toContain(SKILL_NAME)
    expect(badge.classes()).not.toContain(RED_CLASS)
    expect(section.findAll('[data-testid="slots-character"]').map(row => row.text())[1]).toMatch(/Doran Vex\s*1\s*\/\s*\?/)
  })

  it('turns the account badge red when the colonies used exceed the known maximum', async () => {
    const wrapper = await mountView(OVER_MAXIMUM_SLOTS)

    const badge = badgeOf(accountSection(wrapper))
    expect(badge.text()).toMatch(/3\s*\/\s*2/)
    expect(badge.classes()).toContain(RED_CLASS)
  })
})

describe('PlanetaryInteraction colonies grouped by character', () => {
  it('keeps one section per character with colonies, with the colonies used over the maximum of each', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    await groupBy(wrapper, 'character')

    const found = characterSections(wrapper)
    expect(groupByButton(wrapper, 'character').attributes('aria-pressed')).toBe('true')
    expect(found.map(section => section.get('[data-testid="colony-group-title"]').text())).toEqual(['Ilyra Castell', 'Doran Vex'])
    expect(found.map(section => section.findAll('[data-testid="colony-card"]').length)).toEqual([2, 1])
    expect(badgeOf(found[0]).text()).toMatch(/2\s*\/\s*5/)
    expect(badgeOf(found[1]).text()).toMatch(/1\s*\/\s*3/)
  })

  it('does not repeat the character name on the colony cards, the section already names it', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    await groupBy(wrapper, 'character')

    expect(wrapper.findAll('[data-testid="colony-character-name"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="colony-card"]')).toHaveLength(3)
  })

  it('shows the unknown maximum of one character as a question mark and leaves the others with their figures', async () => {
    const wrapper = await mountView(UNKNOWN_MAXIMUM_SLOTS)

    await groupBy(wrapper, 'character')

    const found = characterSections(wrapper)
    expect(badgeOf(found[0]).text()).toMatch(/2\s*\/\s*5/)
    expect(badgeOf(found[0]).attributes('title')).toBeUndefined()
    expect(badgeOf(found[1]).text()).toMatch(/1\s*\/\s*\?/)
    expect(badgeOf(found[1]).attributes('title')).toContain(SKILL_NAME)
  })

  it('turns red only the badge of the character whose colonies exceed its maximum', async () => {
    const wrapper = await mountView(OVER_MAXIMUM_SLOTS)

    await groupBy(wrapper, 'character')

    const found = characterSections(wrapper)
    expect(badgeOf(found[0]).classes()).toContain(RED_CLASS)
    expect(badgeOf(found[1]).classes()).not.toContain(RED_CLASS)
  })

  it('goes back to the single account section when grouping by account again', async () => {
    const wrapper = await mountView(KNOWN_SLOTS)

    await groupBy(wrapper, 'character')
    await groupBy(wrapper, 'account')

    expect(badgeOf(accountSection(wrapper)).text()).toMatch(/3\s*\/\s*9/)
  })
})
