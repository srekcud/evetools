import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-802'
const VALUE_REQUIRED = '[data-testid="contribute-value-required"]'

function bomItem(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-item',
    typeId: 0,
    typeName: '',
    requiredQuantity: 10,
    fulfilledQuantity: 0,
    remainingQuantity: 10,
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
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 0,
    ...overrides,
  }
}

const INVENTION_LINE = bomItem({ id: 'bom-invention', typeId: 11_400, typeName: 'Ishtar', isJob: true, jobGroup: 'blueprint', activityType: 'invention', runs: 4 })
const COPY_LINE = bomItem({ id: 'bom-copy', typeId: 11_401, typeName: 'Ishtar Blueprint', isJob: true, jobGroup: 'blueprint', activityType: 'copy', runs: 2 })
const REACTION_LINE = bomItem({ id: 'bom-reaction', typeId: 16_673, typeName: 'Fernite Carbide', isJob: true, jobGroup: 'component', activityType: 'reaction', runs: 3 })
const COMPONENT_LINE = bomItem({ id: 'bom-component', typeId: 11_399, typeName: 'Morphite Reactor Unit', isJob: true, jobGroup: 'component', activityType: 'manufacturing', runs: 5, remainingQuantity: 5 })
const PYERITE_LINE = bomItem({ id: 'bom-pyerite', typeId: 35, typeName: 'Pyerite', requiredQuantity: 300, remainingQuantity: 300 })

async function openModalFor(prefilledBomItem: BomItem, solo: boolean): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockResolvedValue({})
  const store = useGroupProjectStore()
  store.bomItems = [INVENTION_LINE, COPY_LINE, REACTION_LINE, COMPONENT_LINE, PYERITE_LINE]
  store.currentProject = { id: PROJECT_ID, name: 'Ishtar batch', solo } as GroupProject
  const wrapper = mount(ContributeModal, {
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en, fr } })], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID, prefilledBomItem },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function estimatedValueInput(wrapper: VueWrapper): DOMWrapper<HTMLInputElement> | undefined {
  const field = wrapper.findAll('label').find(candidate => candidate.text().startsWith(en.groupIndustry.contribute.estimatedValue))?.element.parentElement
  if (!field) return undefined
  return wrapper.findAll<HTMLInputElement>('input').find(input => field.contains(input.element))
}

function typeCard(wrapper: VueWrapper, label: string): DOMWrapper<HTMLButtonElement> {
  const card = wrapper.findAll<HTMLButtonElement>('button').find(candidate => candidate.text() === label)
  expect(card, `${label} card`).toBeDefined()
  return card!
}

async function submitWithValue(wrapper: VueWrapper, value: number): Promise<Record<string, unknown>> {
  await estimatedValueInput(wrapper)!.setValue(value)
  await wrapper.findAll('button').find(candidate => candidate.text() === en.groupIndustry.contribute.submit)!.trigger('click')
  await flushPromises()
  const [, options] = vi.mocked(apiRequest).mock.calls[0]!
  return JSON.parse(String(options?.body))
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('ContributeModal opened from a job line', () => {
  it('preselects the BPC type on an invention line and on a copy line', async () => {
    const types: string[] = []
    for (const line of [INVENTION_LINE, COPY_LINE]) {
      vi.mocked(apiRequest).mockReset()
      const wrapper = await openModalFor(line, true)
      types.push(String((await submitWithValue(wrapper, 1_500_000)).type))
      wrapper.unmount()
    }

    expect(types).toEqual(['bpc', 'bpc'])
  })

  it('keeps the job install type on a reaction line', async () => {
    const wrapper = await openModalFor(REACTION_LINE, true)

    expect((await submitWithValue(wrapper, 2_000_000)).type).toBe('job_install')
  })

  it('tells that a value is required while it blocks the submit, and stops once it is entered', async () => {
    const wrapper = await openModalFor(INVENTION_LINE, true)
    const whileZero = wrapper.find(VALUE_REQUIRED).exists()

    await estimatedValueInput(wrapper)!.setValue(1_500_000)

    expect({ whileZero, afterValue: wrapper.find(VALUE_REQUIRED).exists() }).toEqual({ whileZero: true, afterValue: false })
  })

  it('shows no value requirement for a material', async () => {
    const wrapper = await openModalFor(PYERITE_LINE, true)

    expect(wrapper.find(VALUE_REQUIRED).exists()).toBe(false)
  })

  it('declares an owned intermediate on a solo project, keeping the line and sending no value', async () => {
    const wrapper = await openModalFor(COMPONENT_LINE, true)

    await typeCard(wrapper, 'Material').trigger('click')
    await wrapper.findAll('button').find(candidate => candidate.text() === en.groupIndustry.contribute.submit)!.trigger('click')
    await flushPromises()

    const [, options] = vi.mocked(apiRequest).mock.calls[0]!
    expect(JSON.parse(String(options?.body))).toEqual({ type: 'material', bomItemId: 'bom-component', quantity: 5 })
  })

  it('does not offer a job line as a material on a project with a team', async () => {
    const wrapper = await openModalFor(COMPONENT_LINE, false)

    await typeCard(wrapper, 'Material').trigger('click')

    expect(wrapper.findAll('option').map(option => option.text().replace(/\s+/g, ' '))).toEqual([en.groupIndustry.contribute.selectItem, 'Pyerite (300 remaining)'])
  })
})
