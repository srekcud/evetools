import { enableAutoUnmount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProductionChain from '@/components/group-industry/ProductionChain.vue'
import { apiRequest } from '@/services/api'
import type { GroupMemberRole, GroupMemberStatus } from '@/stores/group-industry/types'
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

const RECORD_SALE = nextAction({ code: 'record_sale', tab: 'sales', quantity: 40 })

async function jobRightsOf(myRole: GroupMemberRole, myStatus: GroupMemberStatus) {
  route.query = { tab: 'chain' }
  const wrapper = await mountCockpitDetail({
    project: nyxProject('in_progress', myRole, { myStatus }),
    cockpit: cockpitOf('in_progress', RECORD_SALE),
  })
  const chain = wrapper.findComponent(ProductionChain)
  return { canLinkJobs: chain.props('canLinkJobs'), canMatchJobs: chain.props('canMatchJobs') }
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('project production tab job link rights', () => {
  it('lets every accepted participant link jobs and keeps the automatic matching and the unlink to the owner', async () => {
    expect({
      owner: await jobRightsOf('owner', 'accepted'),
      admin: await jobRightsOf('admin', 'accepted'),
      member: await jobRightsOf('member', 'accepted'),
    }).toEqual({
      owner: { canLinkJobs: true, canMatchJobs: true },
      admin: { canLinkJobs: true, canMatchJobs: false },
      member: { canLinkJobs: true, canMatchJobs: false },
    })
  })
})
