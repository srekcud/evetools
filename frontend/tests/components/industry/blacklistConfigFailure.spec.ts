import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BlacklistConfig from '@/components/industry/BlacklistConfig.vue'
import { ApiError, apiRequest } from '@/services/api'
import type { BlacklistConfig as BlacklistConfiguration } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const BLACKLIST_PATH = '/industry/blacklist'
const BLACKLIST_SEARCH_PATH = '/industry/blacklist/search'
const FUEL_BLOCKS_GROUP_ID = 1136
const CAPITAL_COMPONENTS_GROUP_ID = 873
const NITROGEN_FUEL_BLOCK_TYPE_ID = 4051
const HELIUM_FUEL_BLOCK_TYPE_ID = 4247
const SEARCH_DEBOUNCE_MS = 300
const LOAD_ERROR = { en: 'The blacklist could not be loaded.', fr: 'La blacklist n\'a pas pu être chargée.' } as const
const SAVE_ERROR_EN = 'The blacklist change was not saved.'

const STORED_BLACKLIST: BlacklistConfiguration = {
  categories: [
    { key: 'fuel_blocks', label: 'Fuel blocks', groupIds: [FUEL_BLOCKS_GROUP_ID], blacklisted: false },
    { key: 'capital_components', label: 'Capital components', groupIds: [CAPITAL_COMPONENTS_GROUP_ID], blacklisted: true },
  ],
  items: [{ typeId: NITROGEN_FUEL_BLOCK_TYPE_ID, typeName: 'Nitrogen Fuel Block' }],
}

const BLACKLIST_WITH_FUEL_BLOCKS: BlacklistConfiguration = {
  ...STORED_BLACKLIST,
  categories: STORED_BLACKLIST.categories.map(category => ({ ...category, blacklisted: true })),
}

const reads = apiOutcomesByPath()
const changes = apiOutcomesByPath()
let changeBodies: unknown[] = []

async function mountBlacklistConfig(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(BlacklistConfig, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function categoryCheckbox(wrapper: VueWrapper, categoryLabel: string) {
  const label = wrapper.findAll('label').find(candidate => candidate.text() === categoryLabel)
  expect(label, `${categoryLabel} category`).toBeDefined()
  return label!.get('input[type="checkbox"]')
}

function fuelBlocksCheckbox(wrapper: VueWrapper) {
  return categoryCheckbox(wrapper, 'Fuel blocks')
}

function capitalComponentsCheckbox(wrapper: VueWrapper) {
  return categoryCheckbox(wrapper, 'Capital components')
}

function alertText(wrapper: VueWrapper, testId: string): string | null {
  const alert = wrapper.find(`[data-testid="${testId}"]`)
  return alert.exists() ? alert.text() : null
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  changeBodies = []
  reads.clear()
  changes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) => {
    if (options?.method === 'PUT') changeBodies.push(JSON.parse(String(options.body)))
    const answer = options?.method === 'PUT' ? changes.answer(endpoint) : reads.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('BlacklistConfig load failure (issue 189)', () => {
  it.each([
    ['en', LOAD_ERROR.en, en.common.status.loading],
    ['fr', LOAD_ERROR.fr, fr.common.status.loading],
  ] as const)('replaces the endless loading with the load failure (%s)', async (locale, loadError, loadingLabel) => {
    reads.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))

    const wrapper = await mountBlacklistConfig(locale)

    const alert = wrapper.find('[data-testid="blacklist-load-error"]')
    expect({
      loadError: alert.exists() ? alert.text() : null,
      role: alert.exists() ? alert.attributes('role') : null,
      red: alert.exists() ? alert.classes('text-red-400') : null,
      loadingShown: wrapper.text().includes(loadingLabel),
    }).toEqual({ loadError, role: 'alert', red: true, loadingShown: false })
  })
})

describe('BlacklistConfig change failure (issue 189)', () => {
  it('shows the change failure and leaves the Fuel blocks category unchecked when ticking it fails', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(STORED_BLACKLIST))
    changes.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))
    const wrapper = await mountBlacklistConfig()

    await fuelBlocksCheckbox(wrapper).setValue(true)
    await flushPromises()

    const alert = wrapper.find('[data-testid="blacklist-save-error"]')
    expect({
      saveError: alert.exists() ? alert.text() : null,
      role: alert.exists() ? alert.attributes('role') : null,
      fuelBlocksChecked: (fuelBlocksCheckbox(wrapper).element as HTMLInputElement).checked,
      sent: changeBodies,
    }).toEqual({
      saveError: SAVE_ERROR_EN,
      role: 'alert',
      fuelBlocksChecked: false,
      sent: [{ groupIds: [CAPITAL_COMPONENTS_GROUP_ID, FUEL_BLOCKS_GROUP_ID], typeIds: [NITROGEN_FUEL_BLOCK_TYPE_ID] }],
    })
  })

  it('shows the change failure and leaves the Capital components category checked when unticking it fails', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(STORED_BLACKLIST))
    changes.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))
    const wrapper = await mountBlacklistConfig()

    await capitalComponentsCheckbox(wrapper).setValue(false)
    await flushPromises()

    expect({
      saveError: alertText(wrapper, 'blacklist-save-error'),
      capitalComponentsChecked: (capitalComponentsCheckbox(wrapper).element as HTMLInputElement).checked,
      sent: changeBodies,
    }).toEqual({
      saveError: SAVE_ERROR_EN,
      capitalComponentsChecked: true,
      sent: [{ groupIds: [], typeIds: [NITROGEN_FUEL_BLOCK_TYPE_ID] }],
    })
  })

  it('removes the change failure and checks Fuel blocks when ticking it again succeeds', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(STORED_BLACKLIST))
    changes.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))
    changes.respondTo(BLACKLIST_PATH, respondWith(BLACKLIST_WITH_FUEL_BLOCKS))
    const wrapper = await mountBlacklistConfig()
    await fuelBlocksCheckbox(wrapper).setValue(true)
    await flushPromises()
    const saveErrorAfterFailure = alertText(wrapper, 'blacklist-save-error')

    await fuelBlocksCheckbox(wrapper).setValue(true)
    await flushPromises()

    expect({
      saveErrorAfterFailure,
      saveError: alertText(wrapper, 'blacklist-save-error'),
      fuelBlocksChecked: (fuelBlocksCheckbox(wrapper).element as HTMLInputElement).checked,
    }).toEqual({ saveErrorAfterFailure: SAVE_ERROR_EN, saveError: null, fuelBlocksChecked: true })
  })

  it('shows the change failure and keeps the item listed when removing it fails', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(STORED_BLACKLIST))
    changes.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))
    const wrapper = await mountBlacklistConfig()

    await wrapper.get(`button[aria-label="${en.a11y.remove}"]`).trigger('click')
    await flushPromises()

    expect({
      saveError: alertText(wrapper, 'blacklist-save-error'),
      nitrogenFuelBlockListed: wrapper.text().includes('Nitrogen Fuel Block'),
    }).toEqual({ saveError: SAVE_ERROR_EN, nitrogenFuelBlockListed: true })
  })

  it('shows the change failure and keeps the search and its results open when adding an item fails', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    reads.respondTo(BLACKLIST_PATH, respondWith(STORED_BLACKLIST))
    reads.respondTo(BLACKLIST_SEARCH_PATH, respondWith({ results: [{ typeId: HELIUM_FUEL_BLOCK_TYPE_ID, typeName: 'Helium Fuel Block', isT2: false }] }))
    changes.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))
    const wrapper = await mountBlacklistConfig()
    const search = wrapper.get(`input[aria-label="${en.industry.blacklist.searchPlaceholder}"]`)
    await search.setValue('Heli')
    vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS)
    await flushPromises()
    const searchResult = wrapper.findAll('button').find(button => button.text() === 'Helium Fuel Block')
    expect(searchResult, 'Helium Fuel Block search result').toBeDefined()

    await searchResult!.trigger('mousedown')
    await flushPromises()

    expect({
      saveError: alertText(wrapper, 'blacklist-save-error'),
      searchQuery: (search.element as HTMLInputElement).value,
      searchResultShown: wrapper.findAll('button').some(button => button.text() === 'Helium Fuel Block'),
    }).toEqual({ saveError: SAVE_ERROR_EN, searchQuery: 'Heli', searchResultShown: true })
  })
})
