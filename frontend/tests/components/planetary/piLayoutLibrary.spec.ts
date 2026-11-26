import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiLayoutLibrary from '@/components/planetary/PiLayoutLibrary.vue'
import PiLayoutsTab from '@/components/planetary/PiLayoutsTab.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import type { ColonyLayout } from '@/stores/planetaryLayouts'
import { usePlanetaryLayoutLibraryStore, type LibraryLayout } from '@/stores/planetaryLayoutLibrary'
import { COOLANT_LAYOUT, ROBOTICS_LAYOUT } from '../../planetaryLayoutsFixtures'
import { LIBRARY_COOLANT, LIBRARY_OXYGEN, LIBRARY_ROBOTICS } from '../../planetaryLayoutLibraryFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const LAYOUTS_ENDPOINT = '/planetary/layouts'
const LIBRARY_ENDPOINT = '/planetary/layout-library'
const AUTHOR_WORDS = /author|owner|pilot|auteur|pilote|propriétaire/i
const PUBLISHED_COOLANT: ColonyLayout = { ...COOLANT_LAYOUT, public: true, publishedAt: '2026-11-26T09:15:00+00:00' }
const ADDED_LAYOUT: ColonyLayout = { ...COOLANT_LAYOUT, id: 'layout-added', name: 'Barren coolant line' }

function answerApi(library: LibraryLayout[], layouts: ColonyLayout[] = [], onPatch: () => unknown = () => PUBLISHED_COOLANT) {
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === LIBRARY_ENDPOINT && method === 'GET') return library
    if (endpoint === LAYOUTS_ENDPOINT && method === 'GET') return layouts
    if (endpoint === LAYOUTS_ENDPOINT && method === 'POST') return ADDED_LAYOUT
    if (method === 'PATCH') return onPatch()
    return null
  }) as never)
}

function plugins(locale: 'en' | 'fr' = 'en') {
  return [createI18n({ legacy: false, locale, messages: { en, fr } })]
}

async function mountLibrary(library: LibraryLayout[], locale: 'en' | 'fr' = 'en') {
  setActivePinia(createPinia())
  answerApi(library)
  await usePlanetaryLayoutLibraryStore().load()
  const wrapper = mount(PiLayoutLibrary, { global: { plugins: plugins(locale) } })
  await flushPromises()
  return wrapper
}

async function mountTab(library: LibraryLayout[], layouts: ColonyLayout[]) {
  setActivePinia(createPinia())
  answerApi(library, layouts)
  const wrapper = mount(PiLayoutsTab, { global: { plugins: plugins(), stubs: { RouterLink: RouterLinkStub } } })
  await flushPromises()
  return wrapper
}

type LibraryWrapper = Awaited<ReturnType<typeof mountLibrary>>

function cards(wrapper: LibraryWrapper) {
  return wrapper.findAll('[data-testid="library-card"]')
}

function cardIds(wrapper: LibraryWrapper) {
  return cards(wrapper).map(card => card.attributes('data-layout-id'))
}

function cardOf(wrapper: LibraryWrapper, layoutId: string) {
  return wrapper.get(`[data-testid="library-card"][data-layout-id="${layoutId}"]`)
}

function optionValueContaining(options: Array<{ text: () => string, element: HTMLOptionElement }>, label: string) {
  const option = options.find(candidate => candidate.text().includes(label))
  if (option === undefined) throw new Error(`No option contains ${label}`)
  return option.element.value
}

function requestsOf(method: string) {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => ((call[1] as RequestInit | undefined)?.method ?? 'GET') === method)
    .map(call => ({ endpoint: call[0], body: (call[1] as RequestInit | undefined)?.body === undefined ? undefined : JSON.parse(String((call[1] as RequestInit).body)) }))
}

describe('PiLayoutLibrary public library', () => {
  it('says explicitly that the library is empty and draws no card', async () => {
    const wrapper = await mountLibrary([])

    expect(wrapper.get('[data-testid="library-empty"]').text()).not.toBe('')
    expect(cards(wrapper)).toHaveLength(0)
  })

  it('draws one card per entry with its name, planet and products and never a word about an author', async () => {
    const wrapper = await mountLibrary([LIBRARY_COOLANT, LIBRARY_ROBOTICS, LIBRARY_OXYGEN])

    expect(cardIds(wrapper)).toEqual(['library-coolant', 'library-robotics', 'library-oxygen'])
    expect(wrapper.find('[data-testid="library-empty"]').exists()).toBe(false)
    const coolant = cardOf(wrapper, 'library-coolant')
    expect(coolant.get('[data-testid="library-name"]').text()).toBe('Barren coolant line')
    expect(coolant.get('[data-testid="library-planet"]').text()).toContain('Barren')
    expect(coolant.get('[data-testid="library-products"]').text()).toContain('Coolant')
    const robotics = cardOf(wrapper, 'library-robotics')
    expect(robotics.get('[data-testid="library-name"]').text()).toBe('Lava robotics chain')
    expect(robotics.get('[data-testid="library-planet"]').text()).toContain('Lava')
    expect(robotics.get('[data-testid="library-products"]').text()).toContain('Robotics')
    expect(robotics.get('[data-testid="library-products"]').text()).toContain('Rocket Fuel')
    expect(cardOf(wrapper, 'library-oxygen').get('[data-testid="library-products"]').text()).toContain('Oxygen')
    expect(wrapper.text()).not.toMatch(AUTHOR_WORDS)
    expect((await mountLibrary([LIBRARY_COOLANT, LIBRARY_OXYGEN], 'fr')).text()).not.toMatch(AUTHOR_WORDS)
  })

  it('filters by planet type, then by a case-insensitive product text on top of it', async () => {
    const wrapper = await mountLibrary([LIBRARY_COOLANT, LIBRARY_ROBOTICS, LIBRARY_OXYGEN])
    const planetFilter = wrapper.get('[data-testid="library-filter-planet"]')

    await planetFilter.setValue(optionValueContaining(planetFilter.findAll('option'), 'Barren'))
    expect(cardIds(wrapper)).toEqual(['library-coolant', 'library-oxygen'])

    await wrapper.get('[data-testid="library-filter-product"]').setValue('OXY')
    expect(cardIds(wrapper)).toEqual(['library-oxygen'])

    await wrapper.get('[data-testid="library-filter-product"]').setValue('rocket')
    expect(cardIds(wrapper)).toEqual([])

    await planetFilter.setValue(planetFilter.findAll('option')[0].element.value)
    expect(cardIds(wrapper)).toEqual(['library-robotics'])
  })

  it('copies the game template with the entry name as comment to the clipboard, keeping the key order of the game', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const wrapper = await mountLibrary([LIBRARY_COOLANT, LIBRARY_ROBOTICS])

    await cardOf(wrapper, 'library-robotics').get('[data-testid="library-copy"]').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledTimes(1)
    const written = JSON.parse(String(writeText.mock.calls[0][0]))
    expect(written).toEqual({ ...LIBRARY_ROBOTICS.gameTemplate, Cmt: 'Lava robotics chain' })
    expect(Object.keys(written)).toEqual(Object.keys(LIBRARY_ROBOTICS.gameTemplate))
  })

  it('adds the entry to my layouts with a POST of the serialized game template, comment set to the name, and the name kept', async () => {
    const wrapper = await mountLibrary([LIBRARY_COOLANT, LIBRARY_ROBOTICS])
    vi.mocked(apiRequest).mockClear()

    await cardOf(wrapper, 'library-coolant').get('[data-testid="library-add"]').trigger('click')
    await flushPromises()

    const posted = requestsOf('POST')
    expect(posted).toHaveLength(1)
    expect(posted[0].endpoint).toBe(LAYOUTS_ENDPOINT)
    expect(posted[0].body.name).toBe('Barren coolant line')
    expect(Object.keys(posted[0].body).sort()).toEqual(['name', 'template'])
    expect(typeof posted[0].body.template).toBe('string')
    const template = JSON.parse(posted[0].body.template)
    expect(template).toEqual({ ...LIBRARY_COOLANT.gameTemplate, Cmt: 'Barren coolant line' })
    expect(Object.keys(template)).toEqual(Object.keys(LIBRARY_COOLANT.gameTemplate))
  })
})

describe('PiLayoutsTab publication', () => {
  it('draws the public library in the tab, fed by the library endpoint', async () => {
    const wrapper = await mountTab([LIBRARY_COOLANT, LIBRARY_ROBOTICS], [COOLANT_LAYOUT])

    expect(vi.mocked(apiRequest).mock.calls.map(call => call[0])).toContain(LIBRARY_ENDPOINT)
    expect(wrapper.findAll('[data-testid="library-card"]').map(card => card.attributes('data-layout-id'))).toEqual(['library-coolant', 'library-robotics'])
  })

  it('toggles publication from a card of my layouts with the opposite of its current state', async () => {
    const wrapper = await mountTab([], [COOLANT_LAYOUT, { ...PUBLISHED_COOLANT, id: 'layout-published', name: 'Published robotics' }, ROBOTICS_LAYOUT])
    const privateToggle = wrapper.get('[data-testid="layout-card"][data-layout-id="layout-coolant"] [data-testid="layout-publish-toggle"]')
    const publishedToggle = wrapper.get('[data-testid="layout-card"][data-layout-id="layout-published"] [data-testid="layout-publish-toggle"]')
    expect(privateToggle.text()).not.toBe('')
    expect(publishedToggle.text()).not.toBe('')
    expect(privateToggle.text()).not.toBe(publishedToggle.text())
    vi.mocked(apiRequest).mockClear()

    await privateToggle.trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="layout-card"][data-layout-id="layout-published"] [data-testid="layout-publish-toggle"]').trigger('click')
    await flushPromises()

    expect(requestsOf('PATCH')).toEqual([
      { endpoint: `${LAYOUTS_ENDPOINT}/layout-coolant`, body: { public: true } },
      { endpoint: `${LAYOUTS_ENDPOINT}/layout-published`, body: { public: false } },
    ])
  })
})
