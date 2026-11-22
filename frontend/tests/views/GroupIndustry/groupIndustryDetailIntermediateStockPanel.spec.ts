import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import IntermediateStockProposal from '@/components/group-industry/IntermediateStockProposal.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import { cockpitOf, mountCockpitDetail, nextAction, nyxProject, route } from './projectCockpitFixtures'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: (() => route) as never,
  useRouter: (() => ({ push: vi.fn(), replace: vi.fn() })) as never,
}))

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

async function showsTheStockProposalTo(status: GroupProjectStatus, myRole: GroupMemberRole, myStatus: 'pending' | 'accepted'): Promise<boolean> {
  route.query = {}
  const wrapper = await mountCockpitDetail({
    project: nyxProject(status, myRole, { myStatus, membersCount: 2 }),
    cockpit: cockpitOf(status, nextAction({ code: 'nothing_to_do' })),
  })
  return wrapper.findComponent(IntermediateStockProposal).exists()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('own stock proposal panel on the project detail page', () => {
  it('shows the panel to the owner and to an accepted participant who does not own the project', async () => {
    expect({
      owner: await showsTheStockProposalTo('in_progress', 'owner', 'accepted'),
      member: await showsTheStockProposalTo('in_progress', 'member', 'accepted'),
    }).toEqual({ owner: true, member: true })
  })

  it('hides the panel from a participant who is not accepted', async () => {
    expect(await showsTheStockProposalTo('in_progress', 'member', 'pending')).toBe(false)
  })

  it('hides the panel from everyone once the project is completed', async () => {
    expect({
      owner: await showsTheStockProposalTo('completed', 'owner', 'accepted'),
      member: await showsTheStockProposalTo('completed', 'member', 'accepted'),
    }).toEqual({ owner: false, member: false })
  })
})
