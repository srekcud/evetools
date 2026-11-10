import { enableAutoUnmount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BomTable from '@/components/group-industry/BomTable.vue'
import ContributionTable from '@/components/group-industry/ContributionTable.vue'
import MemberTable from '@/components/group-industry/MemberTable.vue'
import { apiRequest } from '@/services/api'
import type { GroupMember, GroupMemberStatus } from '@/stores/group-industry/types'
import { deferred } from '../../deferred'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
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

const SOLO_TABS = ['bom', 'distribution', 'sales', 'chain', 'planning', 'journal', 'invention']
const TEAM_TABS = ['bom', 'contributions', 'distribution', 'sales', 'members', 'chain', 'planning', 'journal', 'invention']
const ENGLISH_SHARE_LABEL = 'Share Link'
const ENGLISH_INVITE_LABEL = 'Invite'
const FRENCH_INVITE_LABEL = 'Inviter'
const FRENCH_SHARE_LABEL = 'Lien de partage'

function teamMember(id: string, status: GroupMemberStatus): GroupMember {
  return {
    id,
    characterName: `Pilot ${id}`,
    characterId: 2112000000 + id.length,
    corporationId: 98_000_007,
    corporationName: 'Sabre Doctrine Corp',
    role: status === 'accepted' ? 'owner' : 'member',
    status,
    totalContributionValue: 0,
    contributionCount: 0,
    joinedAt: '2026-11-02T08:30:00+00:00',
  }
}

const OWNER = teamMember('owner-1', 'accepted')
const WAITING_PILOT = teamMember('waiting-2', 'pending')

async function mountOn(tab: string | undefined, answers: Partial<CockpitAnswers>, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  route.query = tab === undefined ? {} : { tab }
  return mountCockpitDetail({
    project: nyxProject('in_progress'),
    cockpit: cockpitOf('in_progress', nextAction({ code: 'nothing_to_do' })),
    members: [OWNER],
    ...answers,
  }, locale)
}

function tabIds(wrapper: VueWrapper): string[] {
  return wrapper.findAll('[data-testid^="project-tab-"]').map(tab => (tab.attributes('data-testid') ?? '').replace('project-tab-', ''))
}

function buttonLabels(wrapper: VueWrapper): string[] {
  return wrapper.findAll('button').map(button => button.text().trim())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('project detail team tabs for a project worked on alone', () => {
  it('hides the contributions and team tabs and offers Invite instead of Share Link', async () => {
    const wrapper = await mountOn(undefined, { project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }) })

    expect(tabIds(wrapper)).toEqual(SOLO_TABS)
    expect(buttonLabels(wrapper)).toContain(ENGLISH_INVITE_LABEL)
    expect(buttonLabels(wrapper)).not.toContain(ENGLISH_SHARE_LABEL)
  })

  it('names the invite button Inviter in French', async () => {
    const wrapper = await mountOn(undefined, { project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }) }, 'fr')

    expect(buttonLabels(wrapper)).toContain(FRENCH_INVITE_LABEL)
    expect(buttonLabels(wrapper)).not.toContain(FRENCH_SHARE_LABEL)
  })

  it.each([
    ['members', MemberTable],
    ['contributions', ContributionTable],
  ] as const)('falls back to the materials when the query asks for the %s tab', async (tab, hiddenPanel) => {
    const wrapper = await mountOn(tab, { project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }) })

    expect(wrapper.findComponent(BomTable).exists()).toBe(true)
    expect(wrapper.findComponent(hiddenPanel).exists()).toBe(false)
  })
})

describe('project detail team tabs once the team exists', () => {
  it('shows the contributions and team tabs and keeps Share Link when a member is pending', async () => {
    const wrapper = await mountOn(undefined, {
      project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }),
      members: [OWNER, WAITING_PILOT],
    })

    expect(tabIds(wrapper)).toEqual(TEAM_TABS)
    expect(buttonLabels(wrapper)).toContain(ENGLISH_SHARE_LABEL)
    expect(buttonLabels(wrapper)).not.toContain(ENGLISH_INVITE_LABEL)
  })

  it('shows the tabs as soon as the project has two members, even with no pending member', async () => {
    const wrapper = await mountOn(undefined, {
      project: nyxProject('in_progress', 'owner', { membersCount: 2, pendingContributionCount: 0 }),
      members: [OWNER, teamMember('pilot-3', 'accepted')],
    })

    expect(tabIds(wrapper)).toEqual(TEAM_TABS)
    expect(buttonLabels(wrapper)).toContain(ENGLISH_SHARE_LABEL)
  })

  it('shows the tabs for a lone pilot while a contribution waits for a review', async () => {
    const wrapper = await mountOn(undefined, { project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 1 }) })

    expect(tabIds(wrapper)).toEqual(TEAM_TABS)
  })

  it('opens the team tab named in the query', async () => {
    const wrapper = await mountOn('members', {
      project: nyxProject('in_progress', 'owner', { membersCount: 2, pendingContributionCount: 0 }),
      members: [OWNER, teamMember('pilot-3', 'accepted')],
    })

    expect(wrapper.findComponent(MemberTable).exists()).toBe(true)
    expect(wrapper.findComponent(BomTable).exists()).toBe(false)
  })
})

describe('project detail team tabs while the member list is not known', () => {
  it('shows the tabs when the member list fails to load', async () => {
    const wrapper = await mountOn(undefined, {
      project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }),
      members: new Error('Members unavailable'),
    })

    expect(tabIds(wrapper)).toEqual(TEAM_TABS)
  })

  it('shows the tabs while the member list is loading', async () => {
    const wrapper = await mountOn(undefined, {
      project: nyxProject('in_progress', 'owner', { membersCount: 1, pendingContributionCount: 0 }),
      members: deferred<GroupMember[]>().promise,
    })

    expect(tabIds(wrapper)).toEqual(TEAM_TABS)
  })
})
