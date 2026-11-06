import { createPinia, setActivePinia } from 'pinia'
import { apiRequest } from '@/services/api'
import { i18n } from '@/i18n'
import { useProjectsStore } from '@/stores/industry/projects'
import { messageOf, TEST_LOCALES, type TestLocale } from '../localeTexts'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const RIFTER_TYPE_ID = 587
const GATEWAY_TIMEOUT = 'Gateway timeout (fictif)'
const ESI_ERROR_MESSAGE = 'ESI error limit reached (fictif)'

async function ignoringFailure(action: Promise<unknown>): Promise<void> {
  await action.catch(() => undefined)
}

function failApiWith(reason: unknown): void {
  vi.mocked(apiRequest).mockRejectedValue(reason)
}

function useLocale(locale: TestLocale): void {
  i18n.global.locale.value = locale
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  useLocale('en')
})

describe('industry projects store failure fallbacks', () => {
  it.each(TEST_LOCALES)('reports the %s fallback of the profit margin analysis when the failure carries no message', async (locale) => {
    useLocale(locale)
    failApiWith(GATEWAY_TIMEOUT)
    const store = useProjectsStore()

    await ignoringFailure(store.analyzeMargin(RIFTER_TYPE_ID))

    expect(store.marginError).toBe(messageOf(locale, 'industry.errors.analyzeProfitMargin'))
    expect(store.error).toBeNull()
  })

  it.each(TEST_LOCALES)('keeps the message of the failure instead of the %s fallback', async (locale) => {
    useLocale(locale)
    failApiWith(new Error(ESI_ERROR_MESSAGE))
    const store = useProjectsStore()

    await ignoringFailure(store.analyzeMargin(RIFTER_TYPE_ID))

    expect(store.marginError).toBe(ESI_ERROR_MESSAGE)
  })
})
