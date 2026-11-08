import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-300'
const SUGGESTION_ENDPOINT_PREFIX = `/industry/projects/${PROJECT_ID}/line-rental-suggestions/`
const contributeLabels = en.groupIndustry.contribute

type LineRentalSuggestionAnswer = {
  bomItemId: string
  suggestedValue: number | null
  unknownReason: string | null
  category: string | null
  days: number | null
  runs: number | null
  dayFactor: number | null
  ratePerRun: number | null
}

function bomItem(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-item',
    typeId: 0,
    typeName: '',
    requiredQuantity: 0,
    fulfilledQuantity: 0,
    remainingQuantity: 0,
    fulfillmentPercent: 0,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: false,
    jobGroup: null,
    activityType: null,
    parentTypeId: null,
    meLevel: null,
    teLevel: null,
    runs: null,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    ...overrides,
  }
}

const PULSE_LASER_II_JOB = bomItem({
  id: 'bom-pulse-laser-ii-job',
  typeId: 3057,
  typeName: 'Pulse Laser II',
  requiredQuantity: 50,
  remainingQuantity: 50,
  isJob: true,
  jobGroup: 'final',
  activityType: 'manufacturing',
  meLevel: 10,
  teLevel: 20,
  runs: 50,
})

const MOCKUP_SUGGESTION: LineRentalSuggestionAnswer = {
  bomItemId: PULSE_LASER_II_JOB.id,
  suggestedValue: 5_961_805.56,
  unknownReason: null,
  category: 'module_t2',
  days: 0.9618055555555556,
  runs: 50,
  dayFactor: 1_000_000,
  ratePerRun: 100_000,
}

const NO_LINKED_JOB_SUGGESTION: LineRentalSuggestionAnswer = {
  bomItemId: PULSE_LASER_II_JOB.id,
  suggestedValue: null,
  unknownReason: 'no_linked_job',
  category: 'module_t2',
  days: null,
  runs: null,
  dayFactor: null,
  ratePerRun: null,
}

function answerSuggestionsWith(suggestion: LineRentalSuggestionAnswer): void {
  vi.mocked(apiRequest).mockImplementation(((endpoint: string) =>
    Promise.resolve(endpoint.startsWith(SUGGESTION_ENDPOINT_PREFIX) ? suggestion : {})) as typeof apiRequest)
}

async function openModal(): Promise<VueWrapper> {
  useGroupProjectStore().bomItems = [PULSE_LASER_II_JOB]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

async function chooseLineRentalOn(wrapper: VueWrapper, line: BomItem): Promise<void> {
  const typeButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Line Rental')
  expect(typeButton, 'line rental type button').toBeDefined()
  await typeButton!.trigger('click')
  await wrapper.find('select').setValue(line.id)
  await flushPromises()
}

function inputOfField(wrapper: VueWrapper, label: string): DOMWrapper<HTMLInputElement> {
  const field = wrapper.findAll('label').find(candidate => candidate.text().startsWith(label))?.element.parentElement
  expect(field, `field ${label}`).toBeTruthy()
  const input = wrapper.findAll<HTMLInputElement>('input').find(candidate => field!.contains(candidate.element))
  expect(input, `input of ${label}`).toBeDefined()
  return input!
}

function submitButton(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement> {
  const button = wrapper.findAll<HTMLButtonElement>('button').find(candidate => candidate.text() === contributeLabels.submit)
  expect(button, 'submit button').toBeDefined()
  return button!
}

function submittedBody(): Record<string, unknown> {
  const submissions = vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(submissions).toHaveLength(1)
  const [endpoint, options] = submissions[0]!
  expect(endpoint).toBe(`/industry/projects/${PROJECT_ID}/contributions`)
  return JSON.parse(String(options?.body))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ContributeModal line rental suggestion', () => {
  it('asks the suggestion of the chosen line when the line rental type is selected', async () => {
    answerSuggestionsWith(MOCKUP_SUGGESTION)
    const wrapper = await openModal()

    await chooseLineRentalOn(wrapper, PULSE_LASER_II_JOB)

    const requestedEndpoints = vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint)
    expect(requestedEndpoints).toEqual([`${SUGGESTION_ENDPOINT_PREFIX}${PULSE_LASER_II_JOB.id}`])
  })

  it('prefills the estimated value with the suggested value and shows the category, days and runs', async () => {
    answerSuggestionsWith(MOCKUP_SUGGESTION)
    const wrapper = await openModal()

    await chooseLineRentalOn(wrapper, PULSE_LASER_II_JOB)

    expect(inputOfField(wrapper, contributeLabels.estimatedValue).element.value).toBe('5961805.56')
    const detail = wrapper.find('[data-testid="line-rental-suggestion-detail"]')
    expect(detail.exists(), 'suggestion detail').toBe(true)
    expect(detail.text()).toContain('Module T2')
    expect(detail.text()).toMatch(/0\.96/)
    expect(detail.text()).toMatch(/\b50\b/)
  })

  it('sends the suggested value when the member leaves it untouched', async () => {
    answerSuggestionsWith(MOCKUP_SUGGESTION)
    const wrapper = await openModal()
    await chooseLineRentalOn(wrapper, PULSE_LASER_II_JOB)
    await inputOfField(wrapper, contributeLabels.quantity).setValue(1)

    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'line_rental',
      bomItemId: PULSE_LASER_II_JOB.id,
      quantity: 1,
      estimatedValue: 5_961_805.56,
    })
  })

  it('lets the member edit the prefilled value and sends the entered value', async () => {
    answerSuggestionsWith(MOCKUP_SUGGESTION)
    const wrapper = await openModal()
    await chooseLineRentalOn(wrapper, PULSE_LASER_II_JOB)
    await inputOfField(wrapper, contributeLabels.quantity).setValue(1)

    const estimatedValueInput = inputOfField(wrapper, contributeLabels.estimatedValue)
    expect(estimatedValueInput.element.value).toBe('5961805.56')
    await estimatedValueInput.setValue(4_250_000)
    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'line_rental',
      bomItemId: PULSE_LASER_II_JOB.id,
      quantity: 1,
      estimatedValue: 4_250_000,
    })
  })

  it('leaves the estimated value empty with an unknown message when the suggestion is unknown, and keeps manual entry', async () => {
    answerSuggestionsWith(NO_LINKED_JOB_SUGGESTION)
    const wrapper = await openModal()

    await chooseLineRentalOn(wrapper, PULSE_LASER_II_JOB)

    expect(inputOfField(wrapper, contributeLabels.estimatedValue).element.value).toBe('')
    expect(wrapper.find('[data-testid="line-rental-suggestion-unknown"]').exists(), 'unknown message').toBe(true)
    expect(wrapper.find('[data-testid="line-rental-suggestion-detail"]').exists(), 'suggestion detail').toBe(false)

    await inputOfField(wrapper, contributeLabels.quantity).setValue(1)
    await inputOfField(wrapper, contributeLabels.estimatedValue).setValue(750_000)
    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'line_rental',
      bomItemId: PULSE_LASER_II_JOB.id,
      quantity: 1,
      estimatedValue: 750_000,
    })
  })

  it('keeps the form invalid and sends nothing while no line is chosen for a line rental', async () => {
    answerSuggestionsWith(MOCKUP_SUGGESTION)
    const wrapper = await openModal()
    const typeButton = wrapper.findAll('button').find(candidate => candidate.text() === 'Line Rental')
    await typeButton!.trigger('click')
    await inputOfField(wrapper, contributeLabels.quantity).setValue(1)
    await inputOfField(wrapper, contributeLabels.estimatedValue).setValue(750_000)

    expect(submitButton(wrapper).element.disabled).toBe(true)
    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).not.toHaveBeenCalled()
  })
})
