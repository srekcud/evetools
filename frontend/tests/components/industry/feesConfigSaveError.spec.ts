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
const FAILED_FEES_FR = 'Échec de l\'enregistrement des frais.'
const SAVED_NOTICE_EN = en.settings.marketStructureSaved
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
let savedBodies: unknown[] = []

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

async function mountFeesConfig(locale: 'en' | 'fr' = 'en', storedSettings: UserSettings = STORED_SETTINGS) {
  vi.mocked(apiRequest).mockImplementation(async (url: string, options?: RequestInit) => {
    if (url === SETTINGS_URL && options?.method === 'PATCH') {
      const outcome = saveOutcomes.shift()
      if (!outcome) throw new Error(`Unexpected save ${String(options.body)}`)
      savedBodies.push(JSON.parse(String(options.body)))
      return outcome()
    }
    if (url === SETTINGS_URL) return { ...storedSettings }
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
  savedBodies = []
})

function displayedFees(wrapper: Awaited<ReturnType<typeof mountFeesConfig>>): string[] {
  return wrapper.findAll('input[type="number"]').map(input => (input.element as HTMLInputElement).value)
}

describe('FeesConfig percentages', () => {
  it('shows the stored rates as percentages and the export cost as stored', async () => {
    const wrapper = await mountFeesConfig()

    expect(displayedFees(wrapper)).toEqual(['2.5', '4.5', '1200'])
  })

  it('sends the rates typed as percentages as fractions in the PATCH body', async () => {
    const wrapper = await mountFeesConfig('en', { ...STORED_SETTINGS, brokerFeeRate: 0.012, salesTaxRate: 0.0337, exportCostPerM3: 750 })
    saveOutcomes = [() => ({ ...UPDATED_SETTINGS })]
    const [brokerFeeInput, salesTaxInput, exportCostInput] = wrapper.findAll('input[type="number"]')
    const displayedBeforeTyping = displayedFees(wrapper)

    await brokerFeeInput.setValue('2.5')
    await salesTaxInput.setValue('4.5')
    await exportCostInput.setValue('1200')
    await clickSave(wrapper)

    expect({ displayedBeforeTyping, savedBodies }).toEqual({
      displayedBeforeTyping: ['1.2', '3.37', '750'],
      savedBodies: [{ brokerFeeRate: 0.025, salesTaxRate: 0.045, exportCostPerM3: 1200 }],
    })
  })
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
      savedShown: wrapper.text().includes(SAVED_NOTICE_EN),
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

  it('shows the failed message in French in a red alert', async () => {
    const wrapper = await mountFeesConfig('fr')
    saveOutcomes = [rejectWith(new ApiError('Bad Gateway', 502))]

    await clickSave(wrapper)

    const saveError = wrapper.find('[data-testid="fees-save-error"]')
    expect({
      text: saveError.exists() ? saveError.text() : null,
      red: saveError.exists() ? saveError.classes('text-red-400') : null,
    }).toEqual({ text: FAILED_FEES_FR, red: true })
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
      savedShown: wrapper.text().includes(SAVED_NOTICE_EN),
    }).toEqual({ errorAfterRejection: true, errorAfterSuccess: false, savedShown: true })
  })

  it('shows no error when the save succeeds', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [() => ({ ...UPDATED_SETTINGS })]

    await clickSave(wrapper)

    expect(wrapper.find('[data-testid="fees-save-error"]').exists()).toBe(false)
  })
})
