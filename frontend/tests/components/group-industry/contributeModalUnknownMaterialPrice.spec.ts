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

const PROJECT_ID = 'project-8'
const contributeLabels = en.groupIndustry.contribute

function materialBomItem(overrides: Partial<BomItem>): BomItem {
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

const MORPHITE_WITHOUT_PRICE = materialBomItem({
  id: 'bom-morphite',
  typeId: 11399,
  typeName: 'Morphite',
  requiredQuantity: 500,
  fulfilledQuantity: 460,
  remainingQuantity: 40,
  fulfillmentPercent: 92,
  estimatedPrice: null,
  estimatedTotal: null,
})

const TRITANIUM_WITH_PRICE = materialBomItem({
  id: 'bom-tritanium',
  typeId: 34,
  typeName: 'Tritanium',
  requiredQuantity: 10_000,
  fulfilledQuantity: 9_900,
  remainingQuantity: 100,
  fulfillmentPercent: 99,
  estimatedPrice: 12.5,
  estimatedTotal: 125_000,
})

async function openModalFor(bomItem: BomItem): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue({})
  useGroupProjectStore().bomItems = [MORPHITE_WITHOUT_PRICE, TRITANIUM_WITH_PRICE]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem: bomItem },
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

describe('ContributeModal for a material without estimated price in the BOM', () => {
  it('shows the estimated value field', async () => {
    const wrapper = await openModalFor(MORPHITE_WITHOUT_PRICE)

    expect(estimatedValueInput(wrapper), 'estimated value input').toBeDefined()
  })

  it('keeps the submit button disabled while the estimated value is zero or negative', async () => {
    const wrapper = await openModalFor(MORPHITE_WITHOUT_PRICE)
    const input = estimatedValueInput(wrapper)
    expect(input, 'estimated value input').toBeDefined()

    expect(submitButton(wrapper).element.disabled).toBe(true)
    await input!.setValue(-150)
    expect(submitButton(wrapper).element.disabled).toBe(true)
    await input!.setValue(900)
    expect(submitButton(wrapper).element.disabled).toBe(false)
  })

  it('sends the entered estimated value with the quantity', async () => {
    const wrapper = await openModalFor(MORPHITE_WITHOUT_PRICE)
    const input = estimatedValueInput(wrapper)
    expect(input, 'estimated value input').toBeDefined()

    await input!.setValue(900)
    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'material',
      bomItemId: 'bom-morphite',
      quantity: 40,
      estimatedValue: 900,
    })
  })
})

describe('ContributeModal for a material with an estimated price in the BOM', () => {
  it('hides the estimated value field and sends the value calculated from the estimated price', async () => {
    const wrapper = await openModalFor(TRITANIUM_WITH_PRICE)

    expect(estimatedValueInput(wrapper)).toBeUndefined()
    expect(submitButton(wrapper).element.disabled).toBe(false)

    await submitButton(wrapper).trigger('click')
    await flushPromises()

    expect(submittedBody()).toEqual({
      type: 'material',
      bomItemId: 'bom-tritanium',
      quantity: 100,
      estimatedValue: 1250,
    })
  })
})
