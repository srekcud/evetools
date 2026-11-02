import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { apiRequest } from '@/services/api'
import { useStructuresStore } from '@/stores/industry/structures'
import type { UserSettings } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const USER_SETTINGS_PATH = '/industry/settings'
const SETTINGS_UNAVAILABLE = 'Settings unavailable (fictif)'

const USER_SETTINGS_WITH_FAVORITE_SYSTEMS: UserSettings = {
  favoriteManufacturingSystemId: 30_003_504,
  favoriteManufacturingSystemName: 'Niarja',
  favoriteReactionSystemId: 30_000_157,
  favoriteReactionSystemName: 'Ikuchi',
  brokerFeeRate: 0.015,
  salesTaxRate: 0.0225,
  exportCostPerM3: 980,
}

const outcomes = apiOutcomesByPath()

function mountPivotAdvisorTab(): VueWrapper {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(PivotAdvisorTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

function systemOptionLabels(wrapper: VueWrapper): string[] {
  return wrapper.get('select[id$="-system"]').findAll('option').map(option => option.text())
}

function userSettingsRequested(): boolean {
  return vi.mocked(apiRequest).mock.calls.some(([endpoint]) => endpoint.split('?')[0] === USER_SETTINGS_PATH)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const answer = outcomes.answer(endpoint)
    return answer === undefined ? null : answer
  })
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('PivotAdvisorTab favorite systems on direct open (issue 198)', () => {
  it('requests the user settings and lists their favorite systems when opened with empty settings', async () => {
    outcomes.respondTo(USER_SETTINGS_PATH, respondWith(USER_SETTINGS_WITH_FAVORITE_SYSTEMS))

    const wrapper = mountPivotAdvisorTab()
    await flushPromises()

    expect({
      settingsRequested: userSettingsRequested(),
      systemOptions: systemOptionLabels(wrapper),
    }).toEqual({
      settingsRequested: true,
      systemOptions: ['--', 'Niarja', 'Ikuchi'],
    })
  })

  it('keeps listing the favorite systems already loaded in the store', async () => {
    useStructuresStore().userSettings = USER_SETTINGS_WITH_FAVORITE_SYSTEMS
    outcomes.respondTo(USER_SETTINGS_PATH, respondWith(USER_SETTINGS_WITH_FAVORITE_SYSTEMS))

    const wrapper = mountPivotAdvisorTab()
    await flushPromises()

    expect(systemOptionLabels(wrapper)).toEqual(['--', 'Niarja', 'Ikuchi'])
  })

  it('still mounts without favorite systems when the user settings request fails', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    outcomes.respondTo(USER_SETTINGS_PATH, rejectWith(new Error(SETTINGS_UNAVAILABLE)))

    const wrapper = mountPivotAdvisorTab()
    await flushPromises()

    expect({
      settingsRequested: userSettingsRequested(),
      systemOptions: systemOptionLabels(wrapper),
      userSettings: useStructuresStore().userSettings,
    }).toEqual({
      settingsRequested: true,
      systemOptions: ['--'],
      userSettings: null,
    })
  })
})
