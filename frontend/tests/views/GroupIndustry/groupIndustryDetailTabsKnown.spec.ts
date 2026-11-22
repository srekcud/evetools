import { enableAutoUnmount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProjectInvention from '@/components/group-industry/ProjectInvention.vue'
import { apiRequest } from '@/services/api'
import type { GroupMember, ProjectInventionProduct } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  NYX_INVENTION_PRODUCTS,
  route,
  type CockpitAnswers,
} from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const SOLO_TABS = ['bom', 'invention', 'chain', 'sales', 'distribution', 'journal']
const SOLO_TABS_WITHOUT_INVENTION = ['bom', 'chain', 'sales', 'distribution', 'journal']
const TEAM_TABS = ['members', 'bom', 'contributions', 'invention', 'chain', 'sales', 'distribution', 'journal']

const OWNER: GroupMember = {
  id: 'owner-1',
  characterName: 'Pilot owner-1',
  characterId: 2112000001,
  corporationId: 98_000_007,
  corporationName: 'Sabre Doctrine Corp',
  role: 'owner',
  status: 'accepted',
  totalContributionValue: 0,
  contributionCount: 0,
  joinedAt: '2026-11-02T08:30:00+00:00',
}
const WAITING_PILOT: GroupMember = { ...OWNER, id: 'waiting-2', role: 'member', status: 'pending' }

const SOLO_PROJECT = nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0, solo: true })

async function mountOn(answers: Partial<CockpitAnswers>, tab?: string): Promise<VueWrapper> {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: SOLO_PROJECT,
    cockpit: cockpitOf('in_progress', nextAction({ code: 'nothing_to_do' })),
    members: [OWNER],
    ...answers,
  })
}

function sectionIds(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="project-section-"]')
    .map(section => (section.attributes('data-testid') ?? '').replace('project-section-', ''))
    .filter(id => !id.startsWith('toggle-'))
    .sort()
}

function sorted(ids: string[]): string[] {
  return [...ids].sort()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project detail sections of a solo project while the member list loads', () => {
  it('never shows the contributions and team sections, before or after the members arrive', async () => {
    const members = deferred<GroupMember[]>()
    const wrapper = await mountOn({ members: members.promise })
    const whileLoading = sectionIds(wrapper)

    members.resolve([OWNER])
    await flushPromises()

    expect({ whileLoading, afterLoading: sectionIds(wrapper) }).toEqual({ whileLoading: sorted(SOLO_TABS), afterLoading: sorted(SOLO_TABS) })
  })

  it('shows the team sections once the loaded members include a pending request', async () => {
    const members = deferred<GroupMember[]>()
    const wrapper = await mountOn({ members: members.promise })

    members.resolve([OWNER, WAITING_PILOT])
    await flushPromises()

    expect(sectionIds(wrapper)).toEqual(sorted(TEAM_TABS))
  })

  it('shows the team sections when the member list fails to load', async () => {
    const wrapper = await mountOn({ members: new Error('Members unavailable') })

    expect(sectionIds(wrapper)).toEqual(sorted(TEAM_TABS))
  })

  it('keeps the team sections of a project that is not solo while the members load', async () => {
    const wrapper = await mountOn({
      project: nyxProject('in_progress', 'owner', { membersCount: 2, pendingContributionCount: 0, solo: false }),
      members: deferred<GroupMember[]>().promise,
    })

    expect(sectionIds(wrapper)).toEqual(sorted(TEAM_TABS))
  })
})

describe('project detail invention section', () => {
  it('is hidden when the project excludes invention', async () => {
    const wrapper = await mountOn({ project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0, solo: true, excludesInvention: true }) })

    expect(sectionIds(wrapper)).toEqual(sorted(SOLO_TABS_WITHOUT_INVENTION))
  })

  it('is hidden when no product of the project is invented', async () => {
    const wrapper = await mountOn({ invention: [] })

    expect(sectionIds(wrapper)).toEqual(sorted(SOLO_TABS_WITHOUT_INVENTION))
  })

  it('is shown when a product of the project is invented', async () => {
    const wrapper = await mountOn({ invention: NYX_INVENTION_PRODUCTS })

    expect(sectionIds(wrapper)).toEqual(sorted(SOLO_TABS))
  })

  it('is not shown while the invented products are unknown, then appears with them', async () => {
    const products = deferred<ProjectInventionProduct[]>()
    const wrapper = await mountOn({ invention: products.promise })
    const whileLoading = sectionIds(wrapper)

    products.resolve(NYX_INVENTION_PRODUCTS)
    await flushPromises()

    expect({ whileLoading, afterLoading: sectionIds(wrapper) }).toEqual({ whileLoading: sorted(SOLO_TABS_WITHOUT_INVENTION), afterLoading: sorted(SOLO_TABS) })
  })

  it('stays reachable when the invention comparison fails to load, so the error can be read', async () => {
    const wrapper = await mountOn({ invention: new Error('Invention unavailable') })

    expect(sectionIds(wrapper)).toEqual(sorted(SOLO_TABS))
  })

  it('shows neither the section nor the panel when the query asks for the invention of a project without one', async () => {
    const wrapper = await mountOn({ invention: [] }, 'invention')

    expect({
      section: wrapper.find('[data-testid="project-section-invention"]').exists(),
      invention: wrapper.findComponent(ProjectInvention).exists(),
    }).toEqual({ section: false, invention: false })
  })

  it('opens the invention panel named in the query when a product is invented', async () => {
    const wrapper = await mountOn({ invention: NYX_INVENTION_PRODUCTS }, 'invention')

    expect(wrapper.findComponent(ProjectInvention).exists()).toBe(true)
  })
})
