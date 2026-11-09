import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import BomTable from '@/components/group-industry/BomTable.vue'
import LineReservation from '@/components/group-industry/LineReservation.vue'
import { apiRequest } from '@/services/api'
import { useGroupProjectStore } from '@/stores/group-industry/project'
import type { BomItem, GroupProject } from '@/stores/group-industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-320'
const RESERVATION_URL = `/industry/projects/${PROJECT_ID}/bom/bom-nocxium/reservation`

function line(overrides: Partial<BomItem>): BomItem {
  return {
    id: 'bom-nocxium',
    typeId: 38,
    typeName: 'Nocxium',
    requiredQuantity: 100,
    fulfilledQuantity: 20,
    remainingQuantity: 80,
    fulfillmentPercent: 20,
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
    freeQuantity: 80,
    ...overrides,
  }
}

function project(status: GroupProject['status']): GroupProject {
  return { id: PROJECT_ID, name: 'Ishtar batch', status } as GroupProject
}

function mountReservation(item: BomItem, status: GroupProject['status'] = 'in_progress'): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().currentProject = project(status)
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

  return mount(LineReservation, { props: { item }, global: { plugins: [i18n] } })
}

function buttonLabelled(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').find(button => button.text() === label)
}

describe('LineReservation', () => {
  beforeEach(() => {
    vi.mocked(apiRequest).mockReset()
    vi.mocked(apiRequest).mockResolvedValue([])
  })

  it('names who handles the line and how much, and offers the I will handle it action on an incomplete line', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-1', characterName: 'Brann Holt', quantity: 30, isMine: false }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    expect(wrapper.text()).toContain('Brann Holt')
    expect(wrapper.text()).toContain('30')
    expect(buttonLabelled(wrapper, "I'll handle it")).toBeDefined()
    expect(buttonLabelled(wrapper, 'Release')).toBeUndefined()
  })

  it('reserves the free quantity of the line by default', async () => {
    const wrapper = mountReservation(line({ freeQuantity: 50 }))

    await buttonLabelled(wrapper, "I'll handle it")!.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(RESERVATION_URL, { method: 'POST', body: JSON.stringify({ quantity: 50 }) })
  })

  it('reserves the quantity typed by the participant', async () => {
    const wrapper = mountReservation(line({ freeQuantity: 50 }))

    await wrapper.find('input[type="number"]').setValue(10)
    await buttonLabelled(wrapper, "I'll handle it")!.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(RESERVATION_URL, { method: 'POST', body: JSON.stringify({ quantity: 10 }) })
  })

  it('reloads the BOM after a reservation', async () => {
    const wrapper = mountReservation(line({ freeQuantity: 50 }))

    await buttonLabelled(wrapper, "I'll handle it")!.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/bom`)
  })

  it('offers Release when the participant holds a reservation and releases it', async () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    await buttonLabelled(wrapper, 'Release')!.trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(RESERVATION_URL, { method: 'DELETE' })
  })

  it('emits contribute with the line', async () => {
    const item = line({})
    const wrapper = mountReservation(item)

    await buttonLabelled(wrapper, 'Contribute')!.trigger('click')

    expect(wrapper.emitted('contribute')).toEqual([[item]])
  })

  it('offers no action on a complete line', () => {
    const wrapper = mountReservation(line({ isFulfilled: true, fulfilledQuantity: 100, remainingQuantity: 0, freeQuantity: 0 }))

    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('offers no action on a completed project', () => {
    const wrapper = mountReservation(line({}), 'completed')

    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('does not offer to reserve a line without free quantity left', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-1', characterName: 'Brann Holt', quantity: 80, isMine: false }],
      reservedQuantity: 80,
      freeQuantity: 0,
    }))

    expect(buttonLabelled(wrapper, "I'll handle it")).toBeUndefined()
    expect(buttonLabelled(wrapper, 'Contribute')).toBeDefined()
  })
})

describe('BomTable reservation column', () => {
  it('shows the Reserved by column and emits contribute from the line action', async () => {
    setActivePinia(createPinia())
    const store = useGroupProjectStore()
    const item = line({
      reservations: [{ participantId: 'm-1', characterName: 'Brann Holt', quantity: 30, isMine: false }],
      reservedQuantity: 30,
      freeQuantity: 50,
    })
    store.currentProject = project('in_progress')
    store.bomItems = [item]
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

    const wrapper = mount(BomTable, { global: { plugins: [i18n] } })
    await buttonLabelled(wrapper, 'Contribute')!.trigger('click')

    expect(wrapper.text()).toContain('Reserved by')
    expect(wrapper.text()).toContain('Brann Holt')
    expect(wrapper.emitted('contribute')).toEqual([[item]])
  })
})
