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
const EXPORT_COST_VIOLATION = 'exportCostPerM3: This value should be 0 or more.'

const STORED_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  exportCostPerM3: 1200,
}

const UPDATED_SETTINGS: UserSettings = { ...STORED_SETTINGS, exportCostPerM3: 900 }

let saveOutcomes: Array<() => UserSettings> = []
let savedBodies: unknown[] = []

function rejectWith(error: Error): () => UserSettings {
  return () => { throw error }
}

function exportCostValidationError(): ValidationError {
  return new ValidationError({
    '@type': 'ConstraintViolationList',
    status: 422,
    violations: [{ propertyPath: 'exportCostPerM3', message: 'This value should be 0 or more.' }],
    description: EXPORT_COST_VIOLATION,
    detail: EXPORT_COST_VIOLATION,
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

describe('FeesConfig fields', () => {
  it('offers only the export cost field since the broker fee and the sales tax are computed from the seller', async () => {
    const wrapper = await mountFeesConfig()

    expect({
      inputs: displayedFees(wrapper),
      labels: wrapper.findAll('label').map(label => label.text()),
    }).toEqual({ inputs: ['1200'], labels: [en.industry.config.exportCost] })
  })

  it('sends only the export cost in the PATCH body', async () => {
    const wrapper = await mountFeesConfig('en', { ...STORED_SETTINGS, exportCostPerM3: 750 })
    saveOutcomes = [() => ({ ...UPDATED_SETTINGS })]

    await wrapper.get('input[type="number"]').setValue('1200')
    await clickSave(wrapper)

    expect(savedBodies).toEqual([{ exportCostPerM3: 1200 }])
  })
})

describe('FeesConfig save error', () => {
  it('shows the invalid fees message in an alert, no saved notice and re-enables the button on a 422', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [rejectWith(exportCostValidationError())]

    await clickSave(wrapper)

    const saveError = wrapper.find('[data-testid="fees-save-error"]')
    expect({
      text: saveError.exists() ? saveError.text() : null,
      role: saveError.exists() ? saveError.attributes('role') : null,
      savedShown: wrapper.text().includes(SAVED_NOTICE_EN),
      apiTextShown: wrapper.text().includes(EXPORT_COST_VIOLATION),
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
    saveOutcomes = [rejectWith(exportCostValidationError())]

    await clickSave(wrapper)

    const saveError = wrapper.find('[data-testid="fees-save-error"]')
    expect(saveError.exists() ? saveError.text() : null).toBe(INVALID_FEES_FR)
  })

  it('clears the error and shows the saved notice when the next save succeeds', async () => {
    const wrapper = await mountFeesConfig()
    saveOutcomes = [rejectWith(exportCostValidationError()), () => ({ ...UPDATED_SETTINGS })]

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
