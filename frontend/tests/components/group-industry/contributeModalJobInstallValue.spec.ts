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

const PROJECT_ID = 'project-144'
const contributeLabels = en.groupIndustry.contribute

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
    ...overrides,
  }
}

const ORCA_MANUFACTURING_JOB = bomItem({
  id: 'bom-orca-job',
  typeId: 28606,
  typeName: 'Orca',
  requiredQuantity: 3,
  fulfilledQuantity: 1,
  remainingQuantity: 2,
  fulfillmentPercent: 33,
  isJob: true,
  jobGroup: 'final',
  activityType: 'manufacturing',
  meLevel: 10,
  teLevel: 20,
  runs: 3,
})

const PYERITE_WITH_PRICE = bomItem({
  id: 'bom-pyerite',
  typeId: 35,
  typeName: 'Pyerite',
  requiredQuantity: 8_000,
  fulfilledQuantity: 7_700,
  remainingQuantity: 300,
  fulfillmentPercent: 96,
  estimatedPrice: 9.75,
  estimatedTotal: 2_925,
})

async function openModalFor(prefilledBomItem: BomItem): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue({})
  useGroupProjectStore().bomItems = [ORCA_MANUFACTURING_JOB, PYERITE_WITH_PRICE]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function estimatedValueInput(wrapper: VueWrapper): DOMWrapper<HTMLInputElement> | undefined {
  const field = wrapper.findAll('label').find(candidate => candidate.text().startsWith(contributeLabels.estimatedValue))?.element.parentElement
  if (!field) return undefined
  return wrapper.findAll<HTMLInputElement>('input').find(input => field.contains(input.element))
}

function submitButton(wrapper: VueWrapper): DOMWrapper<HTMLButtonElement> {
  const button = wrapper.findAll<HTMLButtonElement>('button').find(candidate => candidate.text() === contributeLabels.submit)
  expect(button, 'submit button').toBeDefined()
  return button!
}

function submittedBody(): Record<string, unknown> {
  expect(apiRequest).toHaveBeenCalledTimes(1)
  const [endpoint, options] = vi.mocked(apiRequest).mock.calls[0]!
  expect(endpoint).toBe(`/group-industry/projects/${PROJECT_ID}/contributions`)
  expect(options?.method).toBe('POST')
  return JSON.parse(String(options?.body))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ContributeModal for a job install contribution', () => {
  it('shows the estimated value field', async () => {
    const wrapper = await openModalFor(ORCA_MANUFACTURING_JOB)

    expect(estimatedValueInput(wrapper), 'estimated value input').toBeDefined()
  })

  it('keeps the submit button disabled while the estimated value is zero', async () => {
    const wrapper = await openModalFor(ORCA_MANUFACTURING_JOB)
    const input = estimatedValueInput(wrapper)
    expect(input, 'estimated value input').toBeDefined()

    await input!.setValue(0)
    expect(submitButton(wrapper).element.disabled).toBe(true)
    await input!.setValue(1834250.5)
    expect(submitButton(wrapper).element.disabled).toBe(false)
  })

  it('sends the entered estimated value as is', async () => {
    const wrapper = await openModalFor(ORCA_MANUFACTURING_JOB)
    const input = estimatedValueInput(wrapper)
    expect(input, 'estimated value input').toBeDefined()

    await input!.setValue(1834250.5)
    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'job_install',
      bomItemId: 'bom-orca-job',
      quantity: 2,
      estimatedValue: 1834250.5,
    })
  })
})

describe('ContributeModal for a material with an estimated price in the BOM', () => {
  it('sends the value calculated from the estimated price', async () => {
    const wrapper = await openModalFor(PYERITE_WITH_PRICE)

    expect(estimatedValueInput(wrapper)).toBeUndefined()

    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'material',
      bomItemId: 'bom-pyerite',
      quantity: 300,
      estimatedValue: 2925,
    })
  })
})
