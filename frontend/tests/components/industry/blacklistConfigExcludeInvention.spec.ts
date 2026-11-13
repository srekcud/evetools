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
const EXCLUDE_INVENTION_SWITCH = '[data-testid="blacklist-exclude-invention"]'
const SAVE_ERROR_EN = 'The blacklist change was not saved.'

const KEEPING_INVENTION: BlacklistConfiguration = { categories: [], groupIds: [], items: [], excludeInvention: false }
const EXCLUDING_INVENTION: BlacklistConfiguration = { ...KEEPING_INVENTION, excludeInvention: true }

const reads = apiOutcomesByPath()
const changes = apiOutcomesByPath()
let changeBodies: unknown[] = []

async function mountBlacklistConfig(): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(BlacklistConfig, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

function isChecked(wrapper: VueWrapper): boolean | 'absent' {
  const found = wrapper.find(EXCLUDE_INVENTION_SWITCH)
  return found.exists() ? (found.element as HTMLInputElement).checked : 'absent'
}

async function toggle(wrapper: VueWrapper, value: boolean): Promise<void> {
  if (wrapper.find(EXCLUDE_INVENTION_SWITCH).exists()) await wrapper.get(EXCLUDE_INVENTION_SWITCH).setValue(value)
  await flushPromises()
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

describe('BlacklistConfig invention exclusion (issue 806)', () => {
  it('saves the switch at once with only the excludeInvention value and shows it on', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(KEEPING_INVENTION))
    changes.respondTo(BLACKLIST_PATH, respondWith(EXCLUDING_INVENTION))
    const wrapper = await mountBlacklistConfig()
    const before = isChecked(wrapper)

    await toggle(wrapper, true)

    expect({ before, after: isChecked(wrapper), sent: changeBodies }).toEqual({ before: false, after: true, sent: [{ excludeInvention: true }] })
  })

  it('shows the change failure and leaves the switch off when the write fails', async () => {
    reads.respondTo(BLACKLIST_PATH, respondWith(KEEPING_INVENTION))
    changes.respondTo(BLACKLIST_PATH, rejectWith(new ApiError('API error: 503', 503)))
    const wrapper = await mountBlacklistConfig()

    await toggle(wrapper, true)

    const alert = wrapper.find('[data-testid="blacklist-save-error"]')
    expect({
      saveError: alert.exists() ? alert.text() : null,
      switchChecked: isChecked(wrapper),
      sent: changeBodies,
    }).toEqual({ saveError: SAVE_ERROR_EN, switchChecked: false, sent: [{ excludeInvention: true }] })
  })
})
