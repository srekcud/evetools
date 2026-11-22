import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { stationQuantity, STATION_A_ID, typeQuantities, ZYDRINE } from '../../components/group-industry/projectFlowFixtures'
import {
  answerApi,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
} from './projectCockpitFixtures'

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }))

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const DETAIL = '[data-testid="flow-card-detail"]'
const ACTIONS = '[data-testid="project-flow-actions"]'
const CARD = '[data-testid="flow-card"]'
const ZYDRINE_TO_BRING = 40

const syncEvent = ref<GroupProjectEvent | null>(null)

function zydrineQuantities() {
  return [typeQuantities(ZYDRINE, [stationQuantity({ locationId: STATION_A_ID, stationName: 'Azbel', toBring: ZYDRINE_TO_BRING })])]
}

async function mountWithZydrineCard(): Promise<VueWrapper> {
  route.query = {}
  return mountCockpitDetail({
    project: nyxProject('in_progress', 'owner', { membersCount: 3 }),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 })),
    stationQuantities: zydrineQuantities(),
  })
}

function zydrineCard(wrapper: VueWrapper) {
  return wrapper.findAll(CARD).find(card => card.text().includes('Zydrine'))
}

async function clickZydrineCard(wrapper: VueWrapper): Promise<void> {
  await zydrineCard(wrapper)?.trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  setActivePinia(createPinia())
  route.query = {}
  syncEvent.value = null
  replace.mockReset()
  window.HTMLElement.prototype.scrollIntoView = vi.fn()
  vi.spyOn(useSyncStore(), 'getGroupProjectEvent').mockImplementation(() => syncEvent.value)
  vi.mocked(apiRequest).mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('group project detail card detail', () => {
  it('opens the card detail in the right column, above the project to do, when a card is clicked', async () => {
    const wrapper = await mountWithZydrineCard()
    expect(wrapper.find(DETAIL).exists()).toBe(false)

    await clickZydrineCard(wrapper)

    const detail = wrapper.get(DETAIL)
    const actions = wrapper.get(ACTIONS)
    expect(detail.text()).toContain('Zydrine')
    expect(detail.element.parentElement).toBe(actions.element.parentElement)
    expect(detail.element.compareDocumentPosition(actions.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(zydrineCard(wrapper)?.attributes('data-selected')).toBe('true')
  })

  it('removes the card detail from its close button and from a second click on the selected card', async () => {
    const wrapper = await mountWithZydrineCard()
    await clickZydrineCard(wrapper)
    expect(wrapper.find(DETAIL).exists()).toBe(true)

    await wrapper.get(DETAIL).findAll('button').find(button => button.text() === '✕')?.trigger('click')
    await flushPromises()
    expect(wrapper.find(DETAIL).exists()).toBe(false)

    await clickZydrineCard(wrapper)
    expect(wrapper.find(DETAIL).exists()).toBe(true)
    await clickZydrineCard(wrapper)
    expect(wrapper.find(DETAIL).exists()).toBe(false)
  })

  it('closes the card detail when the clicked card is gone after the grid is read again', async () => {
    const wrapper = await mountWithZydrineCard()
    await clickZydrineCard(wrapper)
    expect(wrapper.find(DETAIL).exists()).toBe(true)

    answerApi({
      project: nyxProject('in_progress', 'owner', { membersCount: 3 }),
      cockpit: cockpitOf('in_progress', nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 })),
      stationQuantities: [],
    })
    syncEvent.value = { action: 'contribution_valued', data: null, timestamp: '2026-11-22T09:00:00+00:00' }
    await flushPromises()

    expect(zydrineCard(wrapper)).toBeUndefined()
    expect(wrapper.find(DETAIL).exists()).toBe(false)
  })
})
