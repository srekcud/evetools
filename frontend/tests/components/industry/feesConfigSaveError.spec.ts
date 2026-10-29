import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import FeesConfig from '@/components/industry/FeesConfig.vue'
import { ApiError, apiRequest, ValidationError } from '@/services/api'
import type { UserSettings } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SETTINGS_URL = '/industry/settings'
const INVALID_FEES_EN = 'Each rate must be between 0 and 100 % and the export cost 0 or more. Nothing was saved.'
const INVALID_FEES_FR = 'Chaque taux doit être compris entre 0 et 100 % et le coût d\'export être positif ou nul. Rien n\'a été enregistré.'
const FAILED_FEES_EN = 'Failed to save the fees.'
const SAVED_EN = 'Structure saved'
const BROKER_FEE_VIOLATION = 'brokerFeeRate: This value should be between 0 and 1.'

const STORED_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  brokerFeeRate: 0.025,
  salesTaxRate: 0.045,
  exportCostPerM3: 1200,
}

const UPDATED_SETTINGS: UserSettings = { ...STORED_SETTINGS, brokerFeeRate: 0.03, salesTaxRate: 0.036, exportCostPerM3: 900 }

let saveOutcomes: Array<() => UserSettings> = []

function rejectWith(error: Error): () => UserSettings {
  return () => { throw error }
}

function brokerFeeValidationError(): ValidationError {
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'brokerFeeRate', message: 'This value should be between 0 and 1.' }],
    description: BROKER_FEE_VIOLATION,
    detail: BROKER_FEE_VIOLATION,
  })
}

async function mountFeesConfig(locale: 'en' | 'fr' = 'en') {
  vi.mocked(apiRequest).mockImplementation(async (url: string, options?: RequestInit) => {
    if (url === SETTINGS_URL && options?.method === 'PATCH') {
      const outcome = saveOutcomes.shift()
      if (!outcome) throw new Error(`Unexpected save ${String(options.body)}`)
      return outcome()
    }
    if (url === SETTINGS_URL) return { ...STORED_SETTINGS }
    throw new Error(`Unexpected request ${url}`)
  })
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(FeesConfig, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

async function clickSave(wrapper: Awaited<ReturnType<typeof mountFeesConfig>>) {
  await wrapper.get('button').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  saveOutcomes = []
})

describe('FeesConfig save error', () => {
  it('shows the invalid fees message in an alert, no saved notice and re-enables the button on a 422', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [rejectWith(brokerFeeValidationError())]

    await clickSave(wrapper)

    const saveError = wrapper.find('[data-testid="fees-save-error"]')
    expect({
      text: saveError.exists() ? saveError.text() : null,
      role: saveError.exists() ? saveError.attributes('role') : null,
      savedShown: wrapper.text().includes(SAVED_EN),
      apiTextShown: wrapper.text().includes(BROKER_FEE_VIOLATION),
      buttonDisabled: wrapper.get('button').attributes('disabled') !== undefined,
    }).toEqual({ text: INVALID_FEES_EN, role: 'alert', savedShown: false, apiTextShown: false, buttonDisabled: false })
  })

  it('shows the failed message on any other error', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [rejectWith(new ApiError('Internal Server Error', 500))]

    await clickSave(wrapper)

    const saveError = wrapper.find('[data-testid="fees-save-error"]')
    expect(saveError.exists() ? saveError.text() : null).toBe(FAILED_FEES_EN)
  })

  it('shows the invalid fees message in French', async () => {
    const wrapper = await mountFeesConfig('fr')
    saveOutcomes = [rejectWith(brokerFeeValidationError())]

    await clickSave(wrapper)

    const saveError = wrapper.find('[data-testid="fees-save-error"]')
    expect(saveError.exists() ? saveError.text() : null).toBe(INVALID_FEES_FR)
  })

  it('clears the error and shows the saved notice when the next save succeeds', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [rejectWith(brokerFeeValidationError()), () => ({ ...UPDATED_SETTINGS })]

    await clickSave(wrapper)
    const errorAfterRejection = wrapper.find('[data-testid="fees-save-error"]').exists()
    await clickSave(wrapper)

    expect({
      errorAfterRejection,
      errorAfterSuccess: wrapper.find('[data-testid="fees-save-error"]').exists(),
      savedShown: wrapper.text().includes(SAVED_EN),
    }).toEqual({ errorAfterRejection: true, errorAfterSuccess: false, savedShown: true })
  })

  it('shows no error when the save succeeds', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [() => ({ ...UPDATED_SETTINGS })]

    await clickSave(wrapper)

    expect(wrapper.find('[data-testid="fees-save-error"]').exists()).toBe(false)
  })
})
