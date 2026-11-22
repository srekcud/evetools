import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { apiRequest } from '@/services/api'
import { useSyncStore, type GroupProjectEvent } from '@/stores/sync'
import { bomLineOf, COMPONENT_X, stationQuantity, STATION_A_ID, typeQuantities, ZYDRINE } from '../../components/group-industry/projectFlowFixtures'
import type { GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import { sourcingDecisions, sourcingOption, sourcingRow } from '../../projectSetupFixtures'
import {
  answerApi,
  callsTo,
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  PROJECT_ID,
  PROJECT_PATH,
  route,
  sentPatchBodies,
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

const SWITCH = '[data-testid="flow-detail-buy-or-build-switch"]'
const CONFIRM = '[data-testid="flow-detail-buy-or-build-switch-confirm"]'

async function mountWithArmorPlatesCard(role: GroupMemberRole, status: GroupProjectStatus = 'in_progress'): Promise<VueWrapper> {
  route.query = {}
  const wrapper = await mountCockpitDetail({
    project: nyxProject(status, role, { membersCount: 3 }),
    bom: [bomLineOf({ id: 'line-armor-plates', ...COMPONENT_X, isJob: true, activityType: 'manufacturing', runs: 4 })],
    cockpit: cockpitOf(status, nextAction({ code: 'launch_job', tab: 'materials', quantity: 3 })),
    stationQuantities: [typeQuantities(COMPONENT_X, [stationQuantity({ locationId: STATION_A_ID, stationName: 'Azbel', toBring: 40 })])],
  })
  const answerOthers = vi.mocked(apiRequest).getMockImplementation()!
  const decisions = {
    ...sourcingDecisions(1_000_000, [sourcingRow(COMPONENT_X.typeId, {
      typeName: COMPONENT_X.typeName,
      stage: 'component',
      options: { build: sourcingOption({ total: 1_200_000, perUnit: 30_000 }), jita: sourcingOption({ total: 900_000, perUnit: 22_500 }) },
      chosen: 'build',
      recommended: 'jita',
    })]),
    id: PROJECT_ID,
  }
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string, options?: RequestInit) =>
    endpoint.split('?')[0] === `${PROJECT_PATH}/sourcing-decisions` ? decisions : answerOthers(endpoint, options))
  await wrapper.findAll(CARD).find(card => card.text().includes(COMPONENT_X.typeName))?.trigger('click')
  await flushPromises()
  return wrapper
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

  it('offers the buy or build switch on an intermediate card to the owner of a project in progress, and reads the plan again after it', async () => {
    const wrapper = await mountWithArmorPlatesCard('owner')
    const bomReadsBefore = callsTo(`${PROJECT_PATH}/bom`)

    await wrapper.get(SWITCH).trigger('click')
    await wrapper.get(CONFIRM).trigger('click')
    await flushPromises()

    expect(sentPatchBodies()).toEqual([{ blacklistTypeIds: [COMPONENT_X.typeId] }])
    expect(callsTo(`${PROJECT_PATH}/bom`)).toBeGreaterThan(bomReadsBefore)
  })

  it('offers the switch to an admin but shows the choice without it to a member and on a completed project', async () => {
    expect((await mountWithArmorPlatesCard('admin')).find(SWITCH).exists()).toBe(true)

    const member = await mountWithArmorPlatesCard('member')
    expect(member.find('[data-testid="flow-detail-block-buyOrBuild"]').exists()).toBe(true)
    expect(member.find(SWITCH).exists()).toBe(false)

    const completed = await mountWithArmorPlatesCard('owner', 'completed')
    expect(completed.find('[data-testid="flow-detail-block-buyOrBuild"]').exists()).toBe(true)
    expect(completed.find(SWITCH).exists()).toBe(false)
  })
})
