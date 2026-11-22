import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProjectSettlementBlock from '@/components/group-industry/ProjectSettlementBlock.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, GroupProjectStatus } from '@/stores/group-industry/types'
import {
  cockpitOf,
  mountCockpitDetail,
  nextAction,
  nyxProject,
  route,
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

type BlockOutcome = { canManage: boolean; canClose: boolean } | 'not mounted'

async function settlementBlockOf(status: GroupProjectStatus, myRole: GroupMemberRole, personalUse = false): Promise<BlockOutcome> {
  setActivePinia(createPinia())
  const wrapper = await mountCockpitDetail({
    project: nyxProject(status, myRole, { personalUse, membersCount: 2 }),
    cockpit: cockpitOf(status, nextAction({ code: 'nothing_to_do' })),
  })
  const block = wrapper.findComponent(ProjectSettlementBlock)
  const outcome: BlockOutcome = block.exists() ? { canManage: block.props('canManage'), canClose: block.props('canClose') } : 'not mounted'
  wrapper.unmount()
  return outcome
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  route.query = {}
  vi.mocked(apiRequest).mockReset()
})

describe('detail page, settlement block under the flow grid', () => {
  it('mounts the block for a selling or completed project that is not personal use, with the rights of the viewer, and not before the sale', async () => {
    expect({
      sellingOwner: await settlementBlockOf('selling', 'owner'),
      sellingAdmin: await settlementBlockOf('selling', 'admin'),
      sellingMember: await settlementBlockOf('selling', 'member'),
      completedOwner: await settlementBlockOf('completed', 'owner'),
      inProgressOwner: await settlementBlockOf('in_progress', 'owner'),
      publishedOwner: await settlementBlockOf('published', 'owner'),
      sellingPersonalUse: await settlementBlockOf('selling', 'owner', true),
    }).toEqual({
      sellingOwner: { canManage: true, canClose: true },
      sellingAdmin: { canManage: true, canClose: true },
      sellingMember: { canManage: false, canClose: false },
      completedOwner: { canManage: true, canClose: false },
      inProgressOwner: 'not mounted',
      publishedOwner: 'not mounted',
      sellingPersonalUse: 'not mounted',
    })
  })
})
