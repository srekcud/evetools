import { enableAutoUnmount, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import PiStockTab from '@/components/planetary/PiStockTab.vue'
import { ValidationError, apiRequest } from '@/services/api'
import {
  AMARR_CANDIDATE,
  AMARR_STATION_ID,
  AMARR_STATION_NAME,
  ASHAB_CANDIDATE,
  ASHAB_FORTIZAR_ID,
  ASHAB_FORTIZAR_NAME,
  DEFAULT_HUB_NAME,
  FAVORITE_HUB_ID,
  FAVORITE_HUB_NAME,
  ME_WITHOUT_FAVORITE,
  ME_WITH_PREFERRED_ONLY,
  ME_WITH_TWO_FAVORITES,
  PREFERRED_HUB_ID,
  PREFERRED_HUB_NAME,
  SECOND_FAVORITE_HUB_ID,
  SECOND_FAVORITE_HUB_NAME,
  SETTINGS_OF_A_NEW_USER,
  SETTINGS_WITH_AMARR_HOME,
  STORED_HOME_ID,
  STORED_HOME_NAME,
  apiCalls,
  patchCalls,
  serve,
  type Answer,
  type MeSettingsBody,
  type SettingsBody,
} from '../../planetarySettingsFixtures'
import { PROJECT_NEEDS_WITH_THREE_PRODUCTS } from '../../planetaryProjectNeedsFixtures'
import { STOCK_WITH_PURCHASES } from '../../planetaryStockFixtures'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const RED_BORDER = 'border-red-500/60'

interface Scenario {
  settings: SettingsBody
  me: MeSettingsBody
  locations: unknown[]
  overrides: Record<string, Answer>
  locale: 'en' | 'fr'
}

async function mountStockTab(scenario: Partial<Scenario> = {}) {
  const { settings, me, locations, overrides, locale }: Scenario = {
    settings: SETTINGS_WITH_AMARR_HOME,
    me: ME_WITH_TWO_FAVORITES,
    locations: [AMARR_CANDIDATE, ASHAB_CANDIDATE],
    overrides: {},
    locale: 'en',
    ...scenario,
  }
  setActivePinia(createPinia())
  let current = { ...settings }
  serve({
    'GET /planetary/settings': () => current,
    'GET /planetary/settings/locations': { id: 'locations', locations },
    'GET /me/settings': me,
    'GET /planetary/stock': STOCK_WITH_PURCHASES,
    'GET /planetary/project-needs': PROJECT_NEEDS_WITH_THREE_PRODUCTS,
    'PATCH /planetary/settings': (body) => (current = { ...current, ...body }),
    'PATCH /me/settings': (body) => {
      const chosen = me.marketStructures.find(structure => structure.id === body?.preferredMarketStructureId)
      current = { ...current, hubStructureId: chosen?.id ?? current.hubStructureId, hubStructureName: chosen?.name ?? current.hubStructureName }
      return { ...me, ...body }
    },
    ...overrides,
  })
  const wrapper = mount(PiStockTab, {
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountStockTab>>

function homeSelect(wrapper: Wrapper) {
  return wrapper.get('[data-testid="stock-home-location"]')
}

function hubSelect(wrapper: Wrapper) {
  return wrapper.get('[data-testid="stock-hub"]')
}

function weeksInput(wrapper: Wrapper) {
  return wrapper.get('[data-testid="stock-coverage-weeks"]')
}

function optionsOf(select: ReturnType<typeof homeSelect>) {
  return select.findAll('option').map(option => ({ value: option.attributes('value'), text: option.text() }))
}

function selectedValue(select: ReturnType<typeof homeSelect>): string {
  return (select.element as HTMLSelectElement).value
}

function rejectedLocation(): ValidationError {
  const message = 'This location is not known to any of your characters.'
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'homeLocationId', message }],
    description: message,
    detail: message,
  })
}

enableAutoUnmount(afterEach)

describe('PiStockTab settings', () => {
  it('shows the stored home location, the hub among the favorites and the coverage, with None first in the home list', async () => {
    const wrapper = await mountStockTab({ locale: 'fr' })

    const homeOptions = optionsOf(homeSelect(wrapper))
    expect(homeOptions.map(option => option.value)).toEqual(['', String(AMARR_STATION_ID), String(ASHAB_FORTIZAR_ID)])
    expect(homeOptions[0].text).toBe(fr.pi.stock.settings.homeLocationNone)
    expect(homeOptions[1].text).toContain(AMARR_STATION_NAME)
    expect(homeOptions[2].text).toContain(ASHAB_FORTIZAR_NAME)
    expect(selectedValue(homeSelect(wrapper))).toBe(String(AMARR_STATION_ID))
    expect(optionsOf(hubSelect(wrapper)).map(option => option.value)).toEqual([String(FAVORITE_HUB_ID), String(SECOND_FAVORITE_HUB_ID)])
    expect(optionsOf(hubSelect(wrapper))[0].text).toContain(FAVORITE_HUB_NAME)
    expect(optionsOf(hubSelect(wrapper))[1].text).toContain(SECOND_FAVORITE_HUB_NAME)
    expect(selectedValue(hubSelect(wrapper))).toBe(String(FAVORITE_HUB_ID))
    expect((weeksInput(wrapper).element as HTMLInputElement).value).toBe('5')
    expect(wrapper.text()).toContain(fr.pi.stock.settings.coverageWeeks)
    expect(wrapper.find('[data-testid="stock-hub-readonly"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="stock-settings-error"]').exists()).toBe(false)
    expect(apiCalls().map(call => `${call.method} ${call.endpoint}`).sort()).toEqual([
      'GET /me/settings',
      'GET /planetary/project-needs',
      'GET /planetary/settings',
      'GET /planetary/settings/locations',
      'GET /planetary/stock',
    ])
  })

  it('selects None for a user without home location and keeps a stored location that is no longer a candidate with its name', async () => {
    const withoutHome = await mountStockTab({ settings: SETTINGS_OF_A_NEW_USER })
    const staleHome = await mountStockTab({
      settings: { ...SETTINGS_WITH_AMARR_HOME, homeLocationId: STORED_HOME_ID, homeLocationName: STORED_HOME_NAME },
    })

    expect(selectedValue(homeSelect(withoutHome))).toBe('')
    expect((weeksInput(withoutHome).element as HTMLInputElement).value).toBe('3')
    const staleOptions = optionsOf(homeSelect(staleHome))
    expect(staleOptions.map(option => option.value).sort()).toEqual(['', String(AMARR_STATION_ID), String(ASHAB_FORTIZAR_ID), String(STORED_HOME_ID)].sort())
    expect(staleOptions.find(option => option.value === String(STORED_HOME_ID))?.text).toContain(STORED_HOME_NAME)
    expect(selectedValue(homeSelect(staleHome))).toBe(String(STORED_HOME_ID))
  })

  it('saves the chosen home location, None as null and the chosen favorite as hub with its name, each shown after the save', async () => {
    const wrapper = await mountStockTab()
    vi.mocked(apiRequest).mockClear()

    await homeSelect(wrapper).setValue(String(ASHAB_FORTIZAR_ID))
    await flushPromises()
    const afterChoice = selectedValue(homeSelect(wrapper))
    await homeSelect(wrapper).setValue('')
    await flushPromises()
    const afterNone = selectedValue(homeSelect(wrapper))
    await hubSelect(wrapper).setValue(String(SECOND_FAVORITE_HUB_ID))
    await flushPromises()

    expect(patchCalls().map(call => [call.endpoint, call.body])).toEqual([
      ['/planetary/settings', { homeLocationId: ASHAB_FORTIZAR_ID }],
      ['/planetary/settings', { homeLocationId: null }],
      ['/me/settings', { preferredMarketStructureId: SECOND_FAVORITE_HUB_ID, preferredMarketStructureName: SECOND_FAVORITE_HUB_NAME }],
    ])
    expect(afterChoice).toBe(String(ASHAB_FORTIZAR_ID))
    expect(afterNone).toBe('')
    expect(selectedValue(hubSelect(wrapper))).toBe(String(SECOND_FAVORITE_HUB_ID))
    expect(wrapper.find('[data-testid="stock-settings-error"]').exists()).toBe(false)
  })

  it('shows the effective hub read only with the hint to the market settings when the user has no favorite, and no select', async () => {
    const withNothing = await mountStockTab({
      settings: SETTINGS_OF_A_NEW_USER,
      me: ME_WITHOUT_FAVORITE,
      locations: [],
    })
    const withPreferred = await mountStockTab({
      settings: { ...SETTINGS_OF_A_NEW_USER, hubStructureId: PREFERRED_HUB_ID, hubStructureName: PREFERRED_HUB_NAME },
      me: ME_WITH_PREFERRED_ONLY,
      locations: [],
    })

    expect(withNothing.get('[data-testid="stock-hub-readonly"]').text()).toContain(DEFAULT_HUB_NAME)
    expect(withNothing.find('[data-testid="stock-hub"]').exists()).toBe(false)
    expect(withNothing.text()).toContain(en.pi.stock.settings.hubHint)
    expect(optionsOf(homeSelect(withNothing)).map(option => option.value)).toEqual([''])
    expect(withPreferred.find('[data-testid="stock-hub"]').exists()).toBe(true)
    expect(optionsOf(hubSelect(withPreferred))).toEqual([{ value: String(PREFERRED_HUB_ID), text: expect.stringContaining(PREFERRED_HUB_NAME) }])
    expect(withPreferred.find('[data-testid="stock-hub-readonly"]').exists()).toBe(false)
  })

  it('turns the weeks border red and sends nothing for an empty, zero, negative, too large, fractional or non numeric entry, then saves a valid one', async () => {
    const wrapper = await mountStockTab()
    vi.mocked(apiRequest).mockClear()
    const invalidEntries = ['', '0', '-2', '53', '2.5', 'abc']

    for (const entry of invalidEntries) {
      await weeksInput(wrapper).setValue(entry)
      await weeksInput(wrapper).trigger('change')
      await flushPromises()
      expect([entry, weeksInput(wrapper).classes().includes(RED_BORDER)]).toEqual([entry, true])
    }
    const patchesAfterInvalidEntries = patchCalls()
    await weeksInput(wrapper).setValue('52')
    await weeksInput(wrapper).trigger('change')
    await flushPromises()

    expect(patchesAfterInvalidEntries).toEqual([])
    expect(weeksInput(wrapper).classes()).not.toContain(RED_BORDER)
    expect(patchCalls().length).toBeGreaterThan(0)
    expect(patchCalls().every(call => call.endpoint === '/planetary/settings' && JSON.stringify(call.body) === JSON.stringify({ coverageWeeks: 52 }))).toBe(true)
  })

  it('shows the error of a refused save and puts the previous selection back', async () => {
    const wrapper = await mountStockTab({ overrides: { 'PATCH /planetary/settings': rejectedLocation() } })
    expect(wrapper.find('[data-testid="stock-settings-error"]').exists()).toBe(false)

    await homeSelect(wrapper).setValue(String(ASHAB_FORTIZAR_ID))
    await flushPromises()

    const error = wrapper.get('[data-testid="stock-settings-error"]')
    expect(error.text()).not.toBe('')
    expect(selectedValue(homeSelect(wrapper))).toBe(String(AMARR_STATION_ID))
    expect(patchCalls().map(call => call.body)).toEqual([{ homeLocationId: ASHAB_FORTIZAR_ID }])
  })
})
