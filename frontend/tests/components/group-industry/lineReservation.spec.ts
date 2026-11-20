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
import { present } from '../../present'
import { mountProductionJobRows } from '../../productionJobRows'

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
    pendingContributions: [],
    reservedQuantity: 0,
    freeQuantity: 80,
    ...overrides,
  }
}

function project(status: GroupProject['status']): GroupProject {
  return { id: PROJECT_ID, name: 'Ishtar batch', status } as GroupProject
}

function mountReservation(item: BomItem, status: GroupProject['status'] = 'in_progress', solo = false): VueWrapper {
  setActivePinia(createPinia())
  useGroupProjectStore().currentProject = { ...project(status), solo }
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

    await present(buttonLabelled(wrapper, "I'll handle it")).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(RESERVATION_URL, { method: 'POST', body: JSON.stringify({ quantity: 50 }) })
  })

  it('reserves the quantity typed by the participant', async () => {
    const wrapper = mountReservation(line({ freeQuantity: 50 }))

    await wrapper.find('input[type="number"]').setValue(10)
    await present(buttonLabelled(wrapper, "I'll handle it")).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(RESERVATION_URL, { method: 'POST', body: JSON.stringify({ quantity: 10 }) })
  })

  it('reloads the BOM after a reservation', async () => {
    const wrapper = mountReservation(line({ freeQuantity: 50 }))

    await present(buttonLabelled(wrapper, "I'll handle it")).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(`/industry/projects/${PROJECT_ID}/bom`)
  })

  it('offers Release when the participant holds a reservation and releases it', async () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    await present(buttonLabelled(wrapper, 'Release')).trigger('click')
    await flushPromises()

    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(RESERVATION_URL, { method: 'DELETE' })
  })

  it('shows the quantity still to deliver on the reservation chip and the pending contribution on its own amber chip', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      pendingContributions: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 10, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    expect(wrapper.find('[data-testid="reservation"]').text()).toContain('×20')
    expect(wrapper.find('[data-testid="pending-contribution"]').text()).toBe('Aria Solberg · Awaiting approval ×10 (you)')
  })

  it('shows no pending chip on a reservation without pending contribution', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    expect(wrapper.find('[data-testid="reservation"]').text()).toContain('×30')
    expect(wrapper.find('[data-testid="pending-contribution"]').exists()).toBe(false)
  })

  it('hides the reservation chip, never showing ×0, when the pending contributions cover the whole reservation', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      pendingContributions: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    expect(wrapper.find('[data-testid="reservation"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('×0')
    expect(wrapper.findAll('[data-testid="pending-contribution"]')).toHaveLength(1)
  })

  it('hides the reservation chip when the pending quantity exceeds the reservation', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      pendingContributions: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 40, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    expect(wrapper.find('[data-testid="reservation"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('×-')
  })

  it('subtracts only the pending quantity of the same participant from a reservation', () => {
    const wrapper = mountReservation(line({
      reservations: [{ participantId: 'm-1', characterName: 'Brann Holt', quantity: 30, isMine: false }],
      pendingContributions: [{ participantId: 'm-2', characterName: 'Aria Solberg', quantity: 30, isMine: true }],
      reservedQuantity: 30,
      freeQuantity: 50,
    }))

    expect(wrapper.find('[data-testid="reservation"]').text()).toContain('×30')
  })

  it('shows a pending contribution on a filled line without reservation, one chip per contributor', () => {
    const wrapper = mountReservation(line({
      isFulfilled: true,
      fulfilledQuantity: 100,
      remainingQuantity: 0,
      freeQuantity: 0,
      pendingContributions: [
        { participantId: 'm-2', characterName: 'Aria Solberg', quantity: 2672, isMine: true },
        { participantId: 'm-1', characterName: 'Brann Holt', quantity: 88, isMine: false },
      ],
    }))
    const chips = wrapper.findAll('[data-testid="pending-contribution"]').map(chip => chip.text())

    expect(chips).toEqual(['Aria Solberg · Awaiting approval ×2,672 (you)', 'Brann Holt · Awaiting approval ×88'])
    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('shows a pending contribution on an incomplete line without reservation', () => {
    const wrapper = mountReservation(line({
      pendingContributions: [{ participantId: 'm-1', characterName: 'Brann Holt', quantity: 40, isMine: false }],
    }))

    expect(wrapper.find('[data-testid="pending-contribution"]').text()).toBe('Brann Holt · Awaiting approval ×40')
  })

  it('truncates a long character name on the chip and keeps it whole in the tooltip', () => {
    const longName = 'Ishukone Logistics Director Of The Long Name Division'
    const wrapper = mountReservation(line({
      pendingContributions: [{ participantId: 'm-1', characterName: longName, quantity: 88, isMine: false }],
    }))
    const name = wrapper.find('[data-testid="pending-contribution-name"]')

    expect(name.text()).toBe(longName)
    expect(name.attributes('title')).toBe(longName)
    expect(name.classes()).toContain('truncate')
  })

  it('hides the pending chips of a solo project like the reservations', () => {
    const wrapper = mountReservation(line({
      pendingContributions: [{ participantId: 'm-1', characterName: 'Brann Holt', quantity: 40, isMine: true }],
    }), 'in_progress', true)

    expect(wrapper.find('[data-testid="pending-contribution"]').exists()).toBe(false)
  })

  it('emits contribute with the line', async () => {
    const item = line({})
    const wrapper = mountReservation(item)

    await present(buttonLabelled(wrapper, 'Contribute')).trigger('click')

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
    await present(buttonLabelled(wrapper, 'Contribute')).trigger('click')

    expect(wrapper.text()).toContain('Reserved by')
    expect(wrapper.text()).toContain('Brann Holt')
    expect(wrapper.emitted('contribute')).toEqual([[item]])
  })
})

describe('Solo project line action', () => {
  it('offers a single I supplied it action and no reservation block on an incomplete line', async () => {
    const wrapper = mountReservation(line({ freeQuantity: 80 }), 'in_progress', true)

    expect(wrapper.findAll('button').map(button => button.text())).toEqual(['I supplied it'])
    expect(wrapper.find('input[type="number"]').exists()).toBe(false)

    await present(buttonLabelled(wrapper, 'I supplied it')).trigger('click')

    expect(wrapper.emitted('contribute')).toHaveLength(1)
  })

  it('offers no action once the line is fulfilled', () => {
    const wrapper = mountReservation(line({ isFulfilled: true, remainingQuantity: 0, freeQuantity: 0 }), 'in_progress', true)

    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('keeps the reservation block on a group project', () => {
    const wrapper = mountReservation(line({ freeQuantity: 80 }), 'in_progress', false)

    expect(wrapper.findAll('button').map(button => button.text())).toEqual(["I'll handle it", 'Contribute'])
  })
})

describe('BomTable on a solo project', () => {
  function mountBomTable(solo: boolean): VueWrapper {
    setActivePinia(createPinia())
    const store = useGroupProjectStore()
    store.currentProject = { ...project('in_progress'), solo }
    store.bomItems = [line({})]
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })

    return mount(BomTable, { global: { plugins: [i18n] } })
  }

  it('drops the Reserved by column but keeps the line action', () => {
    const wrapper = mountBomTable(true)

    expect(wrapper.text()).not.toContain('Reserved by')
    expect(buttonLabelled(wrapper, 'I supplied it')).toBeDefined()
  })

  it('keeps the Reserved by column on a group project', () => {
    expect(mountBomTable(false).text()).toContain('Reserved by')
  })
})

describe('Production job row on a solo project', () => {
  const ISHTAR_JOB = line({ id: 'job-ishtar', typeName: 'Ishtar', isJob: true, jobGroup: 'final', activityType: 'manufacturing', runs: 2 })

  function mountJobRows(solo: boolean): Promise<VueWrapper> {
    return mountProductionJobRows({ lines: [ISHTAR_JOB], projectId: PROJECT_ID, solo })
  }

  it('drops the Reserved by column but keeps the line action', async () => {
    const wrapper = await mountJobRows(true)

    expect(wrapper.text()).not.toContain('Reserved by')
    expect(buttonLabelled(wrapper, 'I supplied it')).toBeDefined()
  })

  it('keeps the Reserved by column on a group project', async () => {
    expect((await mountJobRows(false)).text()).toContain('Reserved by')
  })

  it('keeps the reservation column and the line action for a member who is not the owner', async () => {
    const wrapper = await mountProductionJobRows({ lines: [ISHTAR_JOB], projectId: PROJECT_ID, role: 'member' })

    expect(wrapper.text()).toContain('Reserved by')
    expect(buttonLabelled(wrapper, "I'll handle it")).toBeDefined()
  })

  it('re-emits the contribution asked from the line action up to the project', async () => {
    const wrapper = await mountJobRows(true)

    expect(buttonLabelled(wrapper, 'I supplied it')).toBeDefined()
    await buttonLabelled(wrapper, 'I supplied it')?.trigger('click')

    expect(wrapper.emitted('contribute')).toEqual([[ISHTAR_JOB]])
  })
})
