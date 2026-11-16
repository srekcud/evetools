import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ContributeModal from '@/components/group-industry/ContributeModal.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'
import { present } from '../../present'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-1013'
const contributeLabels = en.groupIndustry.contribute

function componentJob(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'job-hydrogen-fuel-block',
    typeId: 4246,
    typeName: 'Hydrogen Fuel Block',
    requiredQuantity: 120,
    fulfilledQuantity: 40,
    remainingQuantity: 80,
    fulfillmentPercent: 33,
    estimatedPrice: null,
    estimatedTotal: null,
    isJob: true,
    jobGroup: 'component',
    activityType: 'manufacturing',
    parentTypeId: null,
    meLevel: 10,
    teLevel: 20,
    runs: 3,
    isFulfilled: false,
    jobMatches: [],
    matchedRuns: 0,
    purchases: [],
    purchasedQuantity: 0,
    reservations: [],
    reservedQuantity: 0,
    freeQuantity: 80,
    containerQuantity: 40,
    containerStockState: 'known',
    unclaimedQuantity: 40,
    launchPrice: 2000,
    unclaimedValue: 80_000,
    ...overrides,
  }
}

async function openModal(line: BomItem, solo: boolean): Promise<VueWrapper> {
  const projectStore = useGroupProjectStore()
  projectStore.currentProject = { id: PROJECT_ID, name: 'Fuel batch', status: 'in_progress', myRole: 'member', solo, items: [] } as unknown as GroupProject
  projectStore.bomItems = [line]
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ContributeModal, {
    global: { plugins: [i18n], stubs: { teleport: true } },
    props: { show: false, projectId: PROJECT_ID },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

function selectableLineNames(wrapper: VueWrapper): string[] {
  return wrapper.findAll('select option').map(option => option.text()).filter(text => text !== contributeLabels.selectItem)
}

function submittedBody(): Record<string, unknown> {
  const submissions = vi.mocked(apiRequest).mock.calls.filter(([, options]) => options?.method === 'POST')
  expect(submissions).toHaveLength(1)
  return JSON.parse(String(present(submissions[0])[1]?.body))
}

async function pickLineAndSubmit(wrapper: VueWrapper, lineId: string, typedQuantity?: number): Promise<void> {
  await wrapper.find('select').setValue(lineId)
  if (typedQuantity !== undefined) await wrapper.find('input[type="number"]').setValue(typedQuantity)
  const button = wrapper.findAll('button').find(candidate => candidate.text() === contributeLabels.submit)
  await present(button).trigger('click')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockResolvedValue({} as never)
})

describe('ContributeModal claim of the container stock of an intermediate line', () => {
  it('offers a component line with unclaimed stock and a launch price among the materials of a group project', async () => {
    const wrapper = await openModal(componentJob({}), false)

    expect(selectableLineNames(wrapper).join(' ')).toContain('Hydrogen Fuel Block')
  })

  it.each([
    ['nothing is unclaimed', { unclaimedQuantity: 0 }],
    ['the unclaimed stock is unknown', { unclaimedQuantity: null }],
    ['no launch price is frozen', { launchPrice: null }],
  ])('does not offer the line when %s', async (_reason, overrides) => {
    const wrapper = await openModal(componentJob(overrides), false)

    expect(selectableLineNames(wrapper)).toEqual([])
  })

  it('claims the unclaimed quantity at the launch price', async () => {
    const line = componentJob({})
    const wrapper = await openModal(line, false)

    await pickLineAndSubmit(wrapper, line.id)

    expect(submittedBody()).toMatchObject({ type: 'material', bomItemId: line.id, quantity: 40, valueSource: 'launch_price' })
  })

  it('keeps the solo behaviour: the line is offered without a value source', async () => {
    const line = componentJob({ unclaimedQuantity: null, launchPrice: null })
    const wrapper = await openModal(line, true)

    await pickLineAndSubmit(wrapper, line.id, 30)

    expect(submittedBody()).toMatchObject({ type: 'material', bomItemId: line.id, quantity: 30 })
    expect(submittedBody()).not.toHaveProperty('valueSource')
  })
})
